<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Controller\UiController;
use Escalated\Symfony\Entity\CustomField;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Escalated/Admin/CustomFields/Index and Form, as escalated-laravel's
 * Admin\CustomFieldController.
 *
 * The entity predates the page and names some columns differently
 * (`fieldType`, `entityType`, `isRequired`, `isActive`); the page reads and
 * posts Laravel's names (`type`, `context`, `required`, `active`), mapped here.
 */
#[Route('/admin/custom-fields', name: 'escalated.admin.custom-fields.')]
class CustomFieldController extends UiController
{
    public const CONTEXTS = ['ticket', 'user', 'organization'];

    public const TYPES = [
        CustomField::TYPE_TEXT,
        CustomField::TYPE_TEXTAREA,
        CustomField::TYPE_SELECT,
        CustomField::TYPE_MULTI_SELECT,
        CustomField::TYPE_CHECKBOX,
        CustomField::TYPE_DATE,
        CustomField::TYPE_NUMBER,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UiRendererInterface $renderer,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $fields = $this->em->getRepository(CustomField::class)->findBy([], ['position' => 'ASC', 'id' => 'ASC']);

        return $this->renderer->render('Escalated/Admin/CustomFields/Index', [
            'fields' => array_map(self::serialize(...), $fields),
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET'])]
    public function create(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/CustomFields/Form', [
            'contexts' => self::CONTEXTS,
        ]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $field = new CustomField();
        if ([] !== $errors = $this->apply($field, $this->payload($request))) {
            return $this->invalid($request, $errors, 'escalated.admin.custom-fields.create');
        }
        $this->em->persist($field);
        $this->em->flush();

        $this->addFlash('success', 'Custom field created.');

        return $this->redirectToRoute('escalated.admin.custom-fields.index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/CustomFields/Form', [
            'field' => self::serialize($this->fieldOr404($id)),
            'contexts' => self::CONTEXTS,
        ]);
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function update(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $field = $this->fieldOr404($id);
        if ([] !== $errors = $this->apply($field, $this->payload($request))) {
            return $this->invalid($request, $errors, 'escalated.admin.custom-fields.edit', ['id' => $id]);
        }
        $this->em->flush();

        $this->addFlash('success', 'Custom field updated.');

        return $this->redirectToRoute('escalated.admin.custom-fields.index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}', name: 'destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $this->em->remove($this->fieldOr404($id));
        $this->em->flush();

        $this->addFlash('success', 'Custom field deleted.');

        return $this->redirectToRoute('escalated.admin.custom-fields.index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/reorder', name: 'reorder', methods: ['POST'])]
    public function reorder(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $positions = $this->payload($request)['positions'] ?? null;
        $repository = $this->em->getRepository(CustomField::class);
        $updates = [];
        if (!\is_array($positions) || [] === $positions) {
            return $this->invalid($request, ['positions' => 'The positions field is required.'], 'escalated.admin.custom-fields.index');
        }
        foreach ($positions as $i => $item) {
            $id = \is_array($item) ? ($item['id'] ?? null) : null;
            $position = \is_array($item) ? ($item['position'] ?? null) : null;
            $field = self::isInteger($id) ? $repository->find((int) $id) : null;
            if (null === $field || !self::isInteger($position)) {
                return $this->invalid($request, ['positions' => sprintf('Position %d needs an existing field id and an integer position.', $i)], 'escalated.admin.custom-fields.index');
            }
            $updates[] = [$field, (int) $position];
        }
        foreach ($updates as [$field, $position]) {
            $field->setPosition($position);
        }
        $this->em->flush();

        $this->addFlash('success', 'Fields reordered.');

        return $this->back($request, 'escalated.admin.custom-fields.index');
    }

    /**
     * Every column, under the names the shared pages use.
     *
     * @return array<string, mixed>
     */
    public static function serialize(CustomField $field): array
    {
        return [
            'id' => $field->getId(),
            'name' => $field->getName(),
            'slug' => $field->getSlug(),
            'type' => $field->getFieldType(),
            'context' => $field->getEntityType(),
            'options' => $field->getOptions(),
            'required' => $field->isRequired(),
            'placeholder' => $field->getPlaceholder(),
            'description' => $field->getDescription(),
            'default_value' => $field->getDefaultValue(),
            'validation_rules' => $field->getValidationRules(),
            'conditions' => $field->getConditions(),
            'position' => $field->getPosition(),
            'active' => $field->isActive(),
            'created_at' => $field->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $field->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Validates the posted field and, when it is valid, writes it onto the
     * entity. Laravel's rules: name, type and context required; the rest
     * optional.
     *
     * @param array<string, mixed> $p
     *
     * @return array<string, string>
     */
    private function apply(CustomField $field, array $p): array
    {
        $errors = [];

        $name = $p['name'] ?? null;
        if (!\is_string($name) || '' === trim($name)) {
            $errors['name'] = 'The name field is required.';
        } elseif (mb_strlen($name) > 255) {
            $errors['name'] = 'The name may not be greater than 255 characters.';
        }

        $type = $p['type'] ?? null;
        // A field saved with a type this admin no longer offers keeps it.
        $allowedTypes = null !== $field->getId() ? [...self::TYPES, $field->getFieldType()] : self::TYPES;
        if (!\is_string($type) || !\in_array($type, $allowedTypes, true)) {
            $errors['type'] = 'The selected type is invalid.';
        }

        $context = $p['context'] ?? null;
        if (!\is_string($context) || !\in_array($context, self::CONTEXTS, true)) {
            $errors['context'] = 'The selected context is invalid.';
        }

        $options = $p['options'] ?? null;
        if (null !== $options && !\is_array($options)) {
            $errors['options'] = 'The options must be a list.';
            $options = null;
        }
        if (\is_array($options)) {
            $options = array_values(array_filter(
                array_map(static fn (mixed $o): string => \is_scalar($o) ? trim((string) $o) : '', $options),
                static fn (string $o): bool => '' !== $o,
            ));
        }
        if (\in_array($type, [CustomField::TYPE_SELECT, CustomField::TYPE_MULTI_SELECT], true) && [] === ($options ?? [])) {
            $errors['options'] ??= 'Select fields need at least one option.';
        }

        $slug = $p['slug'] ?? null;
        if (\is_string($slug) && '' !== trim($slug)) {
            $slug = trim($slug);
            if (mb_strlen($slug) > 255) {
                $errors['slug'] = 'The slug may not be greater than 255 characters.';
            } elseif ($this->slugTaken($slug, $field->getId())) {
                $errors['slug'] = 'The slug has already been taken.';
            }
        } else {
            $slug = null;
        }

        $bools = [];
        foreach (['required' => false, 'active' => true] as $key => $default) {
            if (!\array_key_exists($key, $p) || null === $p[$key]) {
                $bools[$key] = null === $field->getId() ? $default : null;
                continue;
            }
            $bool = self::bool($p[$key]);
            if (null === $bool) {
                $errors[$key] = sprintf('The %s field must be true or false.', $key);
            }
            $bools[$key] = $bool;
        }

        $placeholder = $p['placeholder'] ?? null;
        if (null !== $placeholder && (!\is_string($placeholder) || mb_strlen($placeholder) > 255)) {
            $errors['placeholder'] = 'The placeholder must be a string of at most 255 characters.';
        }
        $description = $p['description'] ?? null;
        if (null !== $description && !\is_string($description)) {
            $errors['description'] = 'The description must be a string.';
        }
        foreach (['validation_rules', 'conditions'] as $key) {
            if (null !== ($p[$key] ?? null) && !\is_array($p[$key])) {
                $errors[$key] = sprintf('The %s must be an array.', str_replace('_', ' ', $key));
            }
        }
        $position = $p['position'] ?? null;
        if (null !== $position && '' !== $position && !self::isInteger($position)) {
            $errors['position'] = 'The position must be an integer.';
        }

        if ([] !== $errors) {
            return $errors;
        }

        \assert(\is_string($name) && \is_string($type) && \is_string($context));
        $field->setName(trim($name))
            ->setFieldType($type)
            ->setEntityType($context)
            ->setOptions($options)
            ->setPlaceholder(\is_string($placeholder) && '' !== trim($placeholder) ? $placeholder : null)
            ->setDescription(\is_string($description) && '' !== trim($description) ? $description : null);
        if (\array_key_exists('validation_rules', $p)) {
            $field->setValidationRules($p['validation_rules']);
        }
        if (\array_key_exists('conditions', $p)) {
            $field->setConditions($p['conditions']);
        }
        if (null !== $bools['required']) {
            $field->setIsRequired($bools['required']);
        }
        if (null !== $bools['active']) {
            $field->setIsActive($bools['active']);
        }
        if (null !== $position && '' !== $position) {
            $field->setPosition((int) $position);
        }
        if (null !== $slug) {
            $field->setSlug($slug);
        } elseif ('' === $field->getSlug()) {
            $field->setSlug($this->uniqueSlug($name));
        }

        return [];
    }

    /**
     * Laravel's `Str::slug($name, '_')`, then `_2`, `_3`... until free.
     */
    private function uniqueSlug(string $name): string
    {
        $ascii = function_exists('iconv') ? (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) : $name;
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($ascii)), '_');
        $base = '' === $base ? 'field' : substr($base, 0, 240);

        $slug = $base;
        for ($n = 2; $this->slugTaken($slug, null); ++$n) {
            $slug = $base.'_'.$n;
        }

        return $slug;
    }

    private function slugTaken(string $slug, ?int $exceptId): bool
    {
        $existing = $this->em->getRepository(CustomField::class)->findOneBy(['slug' => $slug]);

        return null !== $existing && $existing->getId() !== $exceptId;
    }

    private function fieldOr404(int $id): CustomField
    {
        return $this->em->getRepository(CustomField::class)->find($id)
            ?? throw $this->createNotFoundException('Custom field not found.');
    }

    private static function isInteger(mixed $value): bool
    {
        return \is_int($value) || (\is_string($value) && 1 === preg_match('/^-?\d+$/D', $value));
    }

    private static function bool(mixed $value): ?bool
    {
        return match (true) {
            true === $value, 1 === $value, '1' === $value, 'true' === $value => true,
            false === $value, 0 === $value, '0' === $value, 'false' === $value => false,
            default => null,
        };
    }
}
