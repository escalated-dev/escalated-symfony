<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\CustomField;
use Escalated\Symfony\Entity\CustomFieldValue;
use Escalated\Symfony\Entity\Ticket;

/**
 * Ticket custom field values: the `custom_fields` object (keyed by field
 * slug) that ticket create and update requests may carry, and the decoded
 * values the ticket pages and the API detail read back.
 *
 * Only active fields in the `ticket` context count. Values are stored as
 * strings: `multi_select` as a JSON array, `checkbox` as "1"/"0", the rest
 * as given.
 */
class CustomFieldService
{
    public const ENTITY_TICKET = 'ticket';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Active ticket fields in display order, as the create pages read them.
     *
     * @return list<array<string, mixed>>
     */
    public function ticketDefinitions(): array
    {
        return array_map($this->definition(...), $this->activeTicketFields());
    }

    /**
     * Active ticket fields with this ticket's decoded value (`null` when
     * unset), as the show pages and the API detail read them.
     *
     * @return list<array<string, mixed>>
     */
    public function forTicket(Ticket $ticket): array
    {
        $fields = $this->activeTicketFields();
        $stored = $this->storedValues($ticket, $fields);

        return array_map(function (CustomField $field) use ($stored): array {
            $value = $stored[(int) $field->getId()] ?? null;

            return $this->definition($field) + ['value' => null === $value ? null : $this->decode($field, $value)];
        }, $fields);
    }

    /**
     * Checks the request's `custom_fields` before the ticket is written.
     *
     * On create every required field must be present and non-empty; on
     * update only the keys sent are checked, and a payload without
     * `custom_fields` changes nothing.
     *
     * @param array<string, mixed> $payload the whole request body
     *
     * @return array{errors: array<string, string>, values: list<array{0: CustomField, 1: string|null}>}
     */
    public function validateTicketInput(array $payload, bool $creating): array
    {
        $errors = [];
        $values = [];

        if (!\array_key_exists('custom_fields', $payload) || null === $payload['custom_fields']) {
            $input = [];
            if (!$creating) {
                return ['errors' => [], 'values' => []];
            }
        } elseif (!\is_array($payload['custom_fields'])) {
            return ['errors' => ['custom_fields' => 'The custom fields must be an object keyed by field slug.'], 'values' => []];
        } else {
            $input = $payload['custom_fields'];
        }

        $fields = $this->activeTicketFields();
        $known = [];
        foreach ($fields as $field) {
            $slug = $field->getSlug();
            $known[$slug] = true;
            $present = \array_key_exists($slug, $input);
            if (!$present && !$creating) {
                continue;
            }
            $given = $present ? $input[$slug] : null;
            $key = 'custom_fields.'.$slug;

            if (self::isEmpty($given)) {
                if ($field->isRequired()) {
                    $errors[$key] = sprintf('The %s field is required.', $field->getName());
                } elseif ($present) {
                    $values[] = [$field, null];
                }
                continue;
            }

            $encoded = $this->encode($field, $given);
            if (\is_array($encoded)) {
                $errors[$key] = sprintf($encoded[0], $field->getName());
                continue;
            }
            $values[] = [$field, $encoded];
        }

        foreach (array_keys($input) as $slug) {
            if (!isset($known[(string) $slug])) {
                $errors['custom_fields.'.$slug] = sprintf('The %s field is not an active ticket field.', $slug);
            }
        }

        return ['errors' => $errors, 'values' => [] === $errors ? $values : []];
    }

    /**
     * Writes values validated by validateTicketInput(): a null value removes
     * the stored one.
     *
     * @param list<array{0: CustomField, 1: string|null}> $values
     */
    public function saveForTicket(Ticket $ticket, array $values): void
    {
        if ([] === $values) {
            return;
        }

        $repository = $this->em->getRepository(CustomFieldValue::class);
        foreach ($values as [$field, $value]) {
            $row = $repository->findOneBy(['customField' => $field, 'entityType' => self::ENTITY_TICKET, 'entityId' => $ticket->getId()]);
            if (null === $value) {
                if (null !== $row) {
                    $this->em->remove($row);
                }
                continue;
            }
            if (null === $row) {
                $row = (new CustomFieldValue())->setCustomField($field)->setEntityType(self::ENTITY_TICKET)->setEntityId((int) $ticket->getId());
                $this->em->persist($row);
            }
            $row->setValue($value);
        }

        $this->em->flush();
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(CustomField $field): array
    {
        return [
            'id' => $field->getId(),
            'name' => $field->getName(),
            'slug' => $field->getSlug(),
            'type' => $field->getFieldType(),
            'options' => $field->getOptions(),
            'required' => $field->isRequired(),
            'placeholder' => $field->getPlaceholder(),
            'description' => $field->getDescription(),
            'position' => $field->getPosition(),
        ];
    }

    /**
     * A strict calendar date, YYYY-MM-DD.
     */
    public static function isDate(mixed $value): bool
    {
        return \is_string($value)
            && 1 === preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m)
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    public static function isEmpty(mixed $value): bool
    {
        return null === $value
            || (\is_string($value) && '' === trim($value))
            || [] === $value;
    }

    /**
     * The stored string for a non-empty value, or `[message]` when it does
     * not fit the field's type (`%s` is the field name).
     *
     * @return string|array{0: string}
     */
    private function encode(CustomField $field, mixed $value): string|array
    {
        $options = array_map('strval', $field->getOptions() ?? []);

        switch ($field->getFieldType()) {
            case CustomField::TYPE_NUMBER:
                return (\is_int($value) || \is_float($value) || (\is_string($value) && is_numeric(trim($value))))
                    ? trim((string) $value)
                    : ['The %s field must be a number.'];

            case CustomField::TYPE_DATE:
                return self::isDate($value) ? $value : ['The %s field must be a date (YYYY-MM-DD).'];

            case CustomField::TYPE_CHECKBOX:
                return match (true) {
                    true === $value, 1 === $value, '1' === $value, 'true' === $value => '1',
                    false === $value, 0 === $value, '0' === $value, 'false' === $value => '0',
                    default => ['The %s field must be true or false.'],
                };

            case CustomField::TYPE_SELECT:
                return ((\is_string($value) || \is_int($value)) && \in_array((string) $value, $options, true))
                    ? (string) $value
                    : ['The selected %s is invalid.'];

            case CustomField::TYPE_MULTI_SELECT:
                if (!\is_array($value) || !array_is_list($value)) {
                    return ['The %s field must be a list.'];
                }
                foreach ($value as $item) {
                    if (!(\is_string($item) || \is_int($item)) || !\in_array((string) $item, $options, true)) {
                        return ['The selected %s is invalid.'];
                    }
                }

                return (string) json_encode(array_map('strval', $value));

            case CustomField::TYPE_TEXT:
                if (!\is_string($value)) {
                    return ['The %s field must be a string.'];
                }

                return mb_strlen($value) > 255 ? ['The %s field may not be greater than 255 characters.'] : $value;

            case CustomField::TYPE_URL:
                return \is_string($value) && false !== filter_var($value, \FILTER_VALIDATE_URL)
                    ? $value
                    : ['The %s field must be a valid URL.'];

            default: // textarea, and any type this version does not know
                return \is_string($value) ? $value : ['The %s field must be a string.'];
        }
    }

    private function decode(CustomField $field, string $value): mixed
    {
        return match ($field->getFieldType()) {
            CustomField::TYPE_MULTI_SELECT => \is_array($list = json_decode($value, true)) ? $list : [],
            CustomField::TYPE_CHECKBOX => '1' === $value,
            CustomField::TYPE_NUMBER => is_numeric($value) ? $value + 0 : $value,
            default => $value,
        };
    }

    /**
     * @return list<CustomField>
     */
    private function activeTicketFields(): array
    {
        return array_values($this->em->getRepository(CustomField::class)->findBy(
            ['entityType' => self::ENTITY_TICKET, 'isActive' => true],
            ['position' => 'ASC', 'id' => 'ASC'],
        ));
    }

    /**
     * @param list<CustomField> $fields
     *
     * @return array<int, string|null> field id => stored value
     */
    private function storedValues(Ticket $ticket, array $fields): array
    {
        if ([] === $fields || null === $ticket->getId()) {
            return [];
        }

        $out = [];
        $rows = $this->em->getRepository(CustomFieldValue::class)->findBy(['entityType' => self::ENTITY_TICKET, 'entityId' => $ticket->getId()]);
        foreach ($rows as $row) {
            $out[(int) $row->getCustomField()->getId()] = $row->getValue();
        }

        return $out;
    }
}
