<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Controller\UiController;
use Escalated\Symfony\Entity\CustomObject;
use Escalated\Symfony\Entity\CustomObjectRecord;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Service\CustomFieldService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Escalated/Admin/CustomObjects/Index, Form and Records, as
 * escalated-laravel's Admin\CustomObjectController.
 *
 * The entity keeps the schema as `fieldDefinitions` and a record's object as
 * `customObject`; the pages read them as `fields_schema` and `object_id`.
 * Record data is checked against the schema before it is stored.
 */
#[Route('/admin/custom-objects', name: 'escalated.admin.custom-objects.')]
class CustomObjectController extends UiController
{
    public const FIELD_TYPES = ['text', 'number', 'select', 'date', 'lookup'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UiRendererInterface $renderer,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $counts = [];
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(r.customObject) AS id', 'COUNT(r.id) AS n')
            ->from(CustomObjectRecord::class, 'r')
            ->groupBy('r.customObject')
            ->getQuery()
            ->getArrayResult();
        foreach ($rows as $row) {
            $counts[(int) $row['id']] = (int) $row['n'];
        }

        $objects = $this->em->getRepository(CustomObject::class)->findBy([], ['name' => 'ASC']);

        return $this->renderer->render('Escalated/Admin/CustomObjects/Index', [
            'objects' => array_map(fn (CustomObject $o): array => $this->serialize($o) + ['records_count' => $counts[(int) $o->getId()] ?? 0], $objects),
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET'])]
    public function create(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/CustomObjects/Form', ['object' => null]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $object = new CustomObject();
        if ([] !== $errors = $this->apply($object, $this->payload($request))) {
            return $this->invalid($request, $errors, 'escalated.admin.custom-objects.create');
        }
        $this->em->persist($object);
        $this->em->flush();

        $this->addFlash('success', 'Custom object created.');

        return $this->redirectToRoute('escalated.admin.custom-objects.index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/CustomObjects/Form', [
            'object' => $this->serialize($this->objectOr404($id)),
        ]);
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function update(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $object = $this->objectOr404($id);
        if ([] !== $errors = $this->apply($object, $this->payload($request))) {
            return $this->invalid($request, $errors, 'escalated.admin.custom-objects.edit', ['id' => $id]);
        }
        $this->em->flush();

        $this->addFlash('success', 'Custom object updated.');

        return $this->redirectToRoute('escalated.admin.custom-objects.index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}', name: 'destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $this->em->remove($this->objectOr404($id));
        $this->em->flush();

        $this->addFlash('success', 'Custom object deleted.');

        return $this->redirectToRoute('escalated.admin.custom-objects.index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/records', name: 'records', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function records(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $object = $this->objectOr404($id);
        $records = $this->em->getRepository(CustomObjectRecord::class)->findBy(['customObject' => $object], ['id' => 'DESC']);

        return $this->renderer->render('Escalated/Admin/CustomObjects/Records', [
            'object' => $this->serialize($object),
            'records' => array_map($this->serializeRecord(...), $records),
        ]);
    }

    #[Route('/{id}/records', name: 'records.store', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function storeRecord(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $object = $this->objectOr404($id);
        $data = $this->payload($request)['data'] ?? null;
        if ([] !== $errors = $this->validateData($object, $data)) {
            return $this->invalid($request, $errors, 'escalated.admin.custom-objects.records', ['id' => $id]);
        }
        \assert(\is_array($data));
        $this->em->persist((new CustomObjectRecord())->setCustomObject($object)->setData($data));
        $this->em->flush();

        $this->addFlash('success', 'Record created.');

        return $this->back($request, 'escalated.admin.custom-objects.records', ['id' => $id]);
    }

    #[Route('/{id}/records/{record}', name: 'records.update', requirements: ['id' => '\d+', 'record' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function updateRecord(int $id, int $record, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $object = $this->objectOr404($id);
        $row = $this->recordOr404($object, $record);
        $data = $this->payload($request)['data'] ?? null;
        if ([] !== $errors = $this->validateData($object, $data)) {
            return $this->invalid($request, $errors, 'escalated.admin.custom-objects.records', ['id' => $id]);
        }
        \assert(\is_array($data));
        $row->setData($data);
        $this->em->flush();

        $this->addFlash('success', 'Record updated.');

        return $this->back($request, 'escalated.admin.custom-objects.records', ['id' => $id]);
    }

    #[Route('/{id}/records/{record}', name: 'records.destroy', requirements: ['id' => '\d+', 'record' => '\d+'], methods: ['DELETE'])]
    public function destroyRecord(int $id, int $record, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $object = $this->objectOr404($id);
        $this->em->remove($this->recordOr404($object, $record));
        $this->em->flush();

        $this->addFlash('success', 'Record deleted.');

        return $this->back($request, 'escalated.admin.custom-objects.records', ['id' => $id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(CustomObject $object): array
    {
        return [
            'id' => $object->getId(),
            'name' => $object->getName(),
            'slug' => $object->getSlug(),
            'description' => $object->getDescription(),
            'fields_schema' => $object->getFieldDefinitions() ?? [],
            'is_active' => $object->isActive(),
            'created_at' => $object->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $object->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRecord(CustomObjectRecord $record): array
    {
        return [
            'id' => $record->getId(),
            'object_id' => $record->getCustomObject()->getId(),
            'data' => (object) $record->getData(),
            'created_at' => $record->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $record->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Laravel's rules for an object: name, an alpha-dash slug unique among
     * objects, and a schema of at least one `{name, type}` field.
     *
     * @param array<string, mixed> $p
     *
     * @return array<string, string>
     */
    private function apply(CustomObject $object, array $p): array
    {
        $errors = [];

        $name = $p['name'] ?? null;
        if (!\is_string($name) || '' === trim($name)) {
            $errors['name'] = 'The name field is required.';
        } elseif (mb_strlen($name) > 255) {
            $errors['name'] = 'The name may not be greater than 255 characters.';
        }

        $slug = $p['slug'] ?? null;
        if (!\is_string($slug) || '' === $slug) {
            $errors['slug'] = 'The slug field is required.';
        } elseif (mb_strlen($slug) > 100) {
            $errors['slug'] = 'The slug may not be greater than 100 characters.';
        } elseif (1 !== preg_match('/^[A-Za-z0-9_-]+$/D', $slug)) {
            $errors['slug'] = 'The slug may only contain letters, numbers, dashes and underscores.';
        } else {
            $existing = $this->em->getRepository(CustomObject::class)->findOneBy(['slug' => $slug]);
            if (null !== $existing && $existing->getId() !== $object->getId()) {
                $errors['slug'] = 'The slug has already been taken.';
            }
        }

        $schema = $p['fields_schema'] ?? null;
        $fields = [];
        if (!\is_array($schema) || [] === $schema || !array_is_list($schema)) {
            $errors['fields_schema'] = 'The fields schema needs at least one field.';
        } else {
            foreach ($schema as $i => $item) {
                $key = 'fields_schema.'.$i;
                if (!\is_array($item)) {
                    $errors[$key] = 'Each field must be an object.';
                    continue;
                }
                $fieldName = $item['name'] ?? null;
                if (!\is_string($fieldName) || '' === trim($fieldName)) {
                    $errors[$key.'.name'] = 'The field name is required.';
                } elseif (mb_strlen($fieldName) > 100) {
                    $errors[$key.'.name'] = 'The field name may not be greater than 100 characters.';
                }
                $type = $item['type'] ?? null;
                if (!\is_string($type) || !\in_array($type, self::FIELD_TYPES, true)) {
                    $errors[$key.'.type'] = 'The selected field type is invalid.';
                }
                if (\array_key_exists('required', $item) && !\is_bool($item['required']) && !\in_array($item['required'], [0, 1, '0', '1'], true)) {
                    $errors[$key.'.required'] = 'The required flag must be true or false.';
                }
                if (\array_key_exists('options', $item) && !\is_array($item['options'])) {
                    $errors[$key.'.options'] = 'The options must be a list.';
                }

                $field = ['name' => $fieldName, 'type' => $type];
                if (\array_key_exists('required', $item)) {
                    $field['required'] = (bool) $item['required'];
                }
                if (\array_key_exists('options', $item) && \is_array($item['options'])) {
                    $field['options'] = array_values($item['options']);
                }
                $fields[] = $field;
            }
        }

        if ([] !== $errors) {
            return $errors;
        }

        \assert(\is_string($name) && \is_string($slug));
        $object->setName(trim($name))->setSlug($slug)->setFieldDefinitions($fields);
        if (\array_key_exists('description', $p)) {
            $object->setDescription(\is_string($p['description']) && '' !== trim($p['description']) ? $p['description'] : null);
        }

        return [];
    }

    /**
     * Record data against the object's schema: required fields non-empty,
     * numbers numeric, dates real dates, selects among their options, and no
     * keys the schema does not define. Errors are keyed `data.<name>`.
     *
     * @return array<string, string>
     */
    private function validateData(CustomObject $object, mixed $data): array
    {
        if (!\is_array($data) || [] === $data || array_is_list($data)) {
            return ['data' => 'The data field is required.'];
        }

        $errors = [];
        $known = [];
        foreach ($object->getFieldDefinitions() ?? [] as $field) {
            if (!\is_array($field) || !\is_string($field['name'] ?? null)) {
                continue;
            }
            $name = $field['name'];
            $known[$name] = true;
            $value = $data[$name] ?? null;
            $key = 'data.'.$name;

            if (CustomFieldService::isEmpty($value)) {
                if (!empty($field['required'])) {
                    $errors[$key] = sprintf('The %s field is required.', $name);
                }
                continue;
            }

            $type = $field['type'] ?? 'text';
            if ('number' === $type && !(\is_int($value) || \is_float($value) || (\is_string($value) && is_numeric(trim($value))))) {
                $errors[$key] = sprintf('The %s field must be a number.', $name);
            } elseif ('date' === $type && !CustomFieldService::isDate($value)) {
                $errors[$key] = sprintf('The %s field must be a date (YYYY-MM-DD).', $name);
            } elseif ('select' === $type && \is_array($field['options'] ?? null)
                && (!\is_scalar($value) || !\in_array((string) $value, array_map('strval', $field['options']), true))) {
                $errors[$key] = sprintf('The selected %s is invalid.', $name);
            } elseif (!\is_scalar($value)) {
                $errors[$key] = sprintf('The %s field must be a single value.', $name);
            }
        }

        foreach (array_keys($data) as $name) {
            if (!isset($known[(string) $name])) {
                $errors['data.'.$name] = sprintf('%s is not a field of this object.', $name);
            }
        }

        return $errors;
    }

    private function objectOr404(int $id): CustomObject
    {
        return $this->em->getRepository(CustomObject::class)->find($id)
            ?? throw $this->createNotFoundException('Custom object not found.');
    }

    private function recordOr404(CustomObject $object, int $id): CustomObjectRecord
    {
        return $this->em->getRepository(CustomObjectRecord::class)->findOneBy(['id' => $id, 'customObject' => $object])
            ?? throw $this->createNotFoundException('Record not found.');
    }
}
