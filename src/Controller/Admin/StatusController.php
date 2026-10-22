<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Controller\UiController;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\TicketStatus;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Repository\TicketStatusRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Escalated/Admin/Statuses/Index and Form, as escalated-laravel's
 * Admin\StatusController, with the statuses spec's rules on top: the
 * built-in statuses are listed as system rows whose slug and category are
 * fixed and which cannot be deleted, and a slug is generated from the label.
 *
 * Changing a custom status's category does not touch tickets already in it;
 * tickets given it afterwards take the new category's anchor.
 */
#[Route('/admin/statuses', name: 'escalated.admin.statuses.')]
class StatusController extends UiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UiRendererInterface $renderer,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $this->statuses()->ensureSystemStatuses();

        return $this->renderer->render('Escalated/Admin/Statuses/Index', [
            'statuses' => array_map($this->serialize(...), $this->statuses()->ordered()),
            'categories' => TicketStatus::CATEGORIES,
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET'])]
    public function create(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/Statuses/Form', ['categories' => TicketStatus::CATEGORIES]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $status = new TicketStatus();
        if ([] !== $errors = $this->apply($status, $this->payload($request))) {
            return $this->invalid($request, $errors, 'escalated.admin.statuses.create');
        }
        $status->setSlug($this->statuses()->uniqueSlug($status->getLabel()));
        $this->em->persist($status);
        $this->em->flush();
        $this->keepDefaultExclusive($status);

        $this->addFlash('success', 'Status created.');

        return $this->redirectToRoute('escalated.admin.statuses.index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/Statuses/Form', [
            'status' => $this->serialize($this->find($id)),
            'categories' => TicketStatus::CATEGORIES,
        ]);
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function update(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $status = $this->find($id);
        if ([] !== $errors = $this->apply($status, $this->payload($request))) {
            return $this->invalid($request, $errors, 'escalated.admin.statuses.edit', ['id' => $id]);
        }
        $this->em->flush();
        $this->keepDefaultExclusive($status);

        $this->addFlash('success', 'Status updated.');

        return $this->redirectToRoute('escalated.admin.statuses.index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}', name: 'destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $status = $this->find($id);
        if ($status->isSystem()) {
            $message = 'Built-in statuses cannot be deleted.';
            if (!$request->headers->has('X-Inertia') && 'json' === $request->getPreferredFormat()) {
                return new JsonResponse(['message' => $message, 'errors' => ['status' => $message]], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $this->addFlash('error', $message);

            return $this->redirectToRoute('escalated.admin.statuses.index', [], Response::HTTP_SEE_OTHER);
        }

        // Tickets keep their built-in (anchor) status; they just stop
        // pointing at the custom one. The foreign key does the same on its
        // own; this also covers SQLite without foreign keys enforced.
        $this->em->createQueryBuilder()
            ->update(Ticket::class, 't')
            ->set('t.customStatus', 'NULL')
            ->where('t.customStatus = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->execute();
        $this->em->remove($status);
        $this->em->flush();

        $this->addFlash('success', 'Status deleted.');

        return $this->redirectToRoute('escalated.admin.statuses.index', [], Response::HTTP_SEE_OTHER);
    }

    private function statuses(): TicketStatusRepository
    {
        $repository = $this->em->getRepository(TicketStatus::class);
        \assert($repository instanceof TicketStatusRepository);

        return $repository;
    }

    private function find(int $id): TicketStatus
    {
        $status = $this->statuses()->find($id);
        if (null === $status) {
            throw $this->createNotFoundException('Status not found.');
        }

        return $status;
    }

    /**
     * Laravel's rules: label required string max 255, category required and
     * one of the five, color required string max 20, description nullable
     * string, position integer, is_default boolean. A system row keeps its
     * category.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, string>
     */
    private function apply(TicketStatus $status, array $payload): array
    {
        $errors = [];

        $label = $payload['label'] ?? null;
        if (!\is_string($label) || '' === trim($label)) {
            $errors['label'] = 'The label field is required.';
        } elseif (mb_strlen($label) > 255) {
            $errors['label'] = 'The label field must not be greater than 255 characters.';
        }

        $category = $payload['category'] ?? null;
        if (null === $category || '' === $category) {
            $errors['category'] = 'The category field is required.';
        } elseif (!\is_string($category) || !\in_array($category, TicketStatus::CATEGORIES, true)) {
            $errors['category'] = 'The selected category is invalid.';
        } elseif ($status->isSystem() && $category !== $status->getCategory()) {
            $errors['category'] = 'The category of a built-in status cannot be changed.';
        }

        $color = $payload['color'] ?? null;
        if (!\is_string($color) || '' === trim($color)) {
            $errors['color'] = 'The color field is required.';
        } elseif (mb_strlen($color) > 20) {
            $errors['color'] = 'The color field must not be greater than 20 characters.';
        }

        $description = $payload['description'] ?? null;
        if (null !== $description && !\is_string($description)) {
            $errors['description'] = 'The description field must be a string.';
        }

        $position = $payload['position'] ?? null;
        if (null !== $position && false === filter_var($position, \FILTER_VALIDATE_INT)) {
            $errors['position'] = 'The position field must be an integer.';
        }

        $isDefault = $payload['is_default'] ?? null;
        if (null !== $isDefault && !\in_array($isDefault, [true, false, 0, 1, '0', '1'], true)) {
            $errors['is_default'] = 'The is default field must be true or false.';
        }

        if ([] !== $errors) {
            return $errors;
        }

        \assert(\is_string($label) && \is_string($category) && \is_string($color));
        $status->setLabel(trim($label))
            ->setCategory($category)
            ->setColor(trim($color))
            ->setDescription(\is_string($description) && '' !== trim($description) ? $description : null);
        if (null !== $position) {
            $status->setPosition((int) $position);
        }
        if (null !== $isDefault) {
            $status->setIsDefault((bool) $isDefault);
        }

        return [];
    }

    /**
     * One default per category, as Laravel: making this one the default
     * clears it on the others in its category.
     */
    private function keepDefaultExclusive(TicketStatus $status): void
    {
        if (!$status->isDefault()) {
            return;
        }

        $this->em->createQueryBuilder()
            ->update(TicketStatus::class, 's')
            ->set('s.isDefault', ':no')
            ->where('s.category = :category')
            ->andWhere('s.id <> :id')
            ->setParameter('no', false)
            ->setParameter('category', $status->getCategory())
            ->setParameter('id', $status->getId())
            ->getQuery()
            ->execute();
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(TicketStatus $s): array
    {
        return [
            'id' => $s->getId(),
            'label' => $s->getLabel(),
            'slug' => $s->getSlug(),
            'category' => $s->getCategory(),
            'color' => $s->getColor(),
            'description' => $s->getDescription(),
            'position' => $s->getPosition(),
            'is_default' => $s->isDefault(),
            'is_system' => $s->isSystem(),
            'created_at' => $s->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $s->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
