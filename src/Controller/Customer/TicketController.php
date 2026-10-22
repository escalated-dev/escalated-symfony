<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Customer;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Controller\TicketDeskController;
use Escalated\Symfony\Controller\UiController;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Rendering\TicketPresenter;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Security\TicketRequesterVoter;
use Escalated\Symfony\Security\UserKeyResolver;
use Escalated\Symfony\Service\CustomFieldService;
use Escalated\Symfony\Service\SatisfactionRatingService;
use Escalated\Symfony\Service\TicketService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The requester's own tickets: Escalated/Customer/Index, Create and Show, as
 * escalated-laravel's Customer\TicketController.
 *
 * Tickets belong to the requester by the host user's primary key (what
 * UserKeyResolver returns) and class, the same test the
 * ESCALATED_TICKET_REQUESTER voter applies. The Show page gets the
 * requester-facing allowlist, never the ticket entity.
 */
#[Route('/customer/tickets', name: 'escalated.customer.tickets.')]
class TicketController extends UiController
{
    private const FILTERS = ['status', 'priority', 'ticket_type', 'search', 'sort_by', 'sort_dir'];

    public function __construct(
        private readonly TicketService $ticketService,
        private readonly UiRendererInterface $renderer,
        private readonly SatisfactionRatingService $satisfactionRatings,
        private readonly TicketPresenter $presenter,
        private readonly UserKeyResolver $userKeys,
        private readonly EntityManagerInterface $em,
        private readonly bool $allowCustomerClose,
        private readonly ?CustomFieldService $customFields = null,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $filters = array_filter(
            array_intersect_key($request->query->all(), array_flip(self::FILTERS)),
            static fn (mixed $v): bool => \is_scalar($v) && '' !== (string) $v,
        );
        $query = $filters;
        if (isset($query['sort_by'])) {
            $query['sort_by'] = ['created_at' => 'createdAt', 'updated_at' => 'updatedAt'][$query['sort_by']] ?? $query['sort_by'];
        }

        $qb = $this->em->getRepository(Ticket::class)->createFilteredQueryBuilder($query);
        $key = $this->requesterKey();
        if (null === $key) {
            $qb->andWhere('1 = 0');
        } else {
            $qb->andWhere('t.requesterId = :requesterId AND t.requesterClass = :requesterClass')
                ->setParameter('requesterId', $key)
                ->setParameter('requesterClass', $this->user()::class);
        }

        return $this->renderer->render('Escalated/Customer/Index', [
            'tickets' => $this->presenter->paginate(
                $qb,
                $request->query->getInt('page', 1),
                15,
                $this->generateUrl('escalated.customer.tickets.index'),
                fn (Ticket $t): array => $this->presenter->customerRow($t),
                $filters,
            ),
            'filters' => $filters,
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET'])]
    public function create(): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        return $this->renderer->render('Escalated/Customer/Create', [
            'departments' => $this->presenter->departments(),
            'priorities' => TicketDeskController::priorities(),
            'custom_fields' => $this->customFields?->ticketDefinitions() ?? [],
        ]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $payload = $this->payload($request);
        $errors = [];
        $subject = $payload['subject'] ?? null;
        if (!\is_string($subject) || '' === trim($subject)) {
            $errors['subject'] = 'The subject field is required.';
        } elseif (mb_strlen($subject) > 255) {
            $errors['subject'] = 'The subject may not be greater than 255 characters.';
        }
        $description = $payload['description'] ?? null;
        if (!\is_string($description) || '' === trim($description)) {
            $errors['description'] = 'The description field is required.';
        }
        $priority = $payload['priority'] ?? null;
        if (null !== $priority && '' !== $priority && !\in_array($priority, TicketDeskController::priorities(), true)) {
            $errors['priority'] = 'The selected priority is invalid.';
        }
        $departmentId = $payload['department_id'] ?? null;
        if (null !== $departmentId && '' !== $departmentId
            && (!is_numeric($departmentId) || null === $this->em->getRepository(Department::class)->find((int) $departmentId))) {
            $errors['department_id'] = 'The selected department is invalid.';
        }
        $type = $payload['ticket_type'] ?? null;
        if (null !== $type && '' !== $type && !\in_array($type, Ticket::TYPES, true)) {
            $errors['ticket_type'] = 'The selected ticket type is invalid.';
        }
        $key = $this->requesterKey();
        if (null === $key) {
            $errors['subject'] ??= 'Your account cannot open tickets.';
        }
        $custom = $this->customFields?->validateTicketInput($payload, true) ?? ['errors' => [], 'values' => []];
        $errors += $custom['errors'];
        if ([] !== $errors) {
            return $this->invalid($request, $errors, 'escalated.customer.tickets.create');
        }

        $data = [
            'subject' => trim($subject),
            'description' => $description,
            'requester_id' => $key,
            'requester_class' => $this->user()::class,
        ];
        if (\is_string($priority) && '' !== $priority) {
            $data['priority'] = $priority;
        }
        if (null !== $departmentId && '' !== $departmentId) {
            $data['department_id'] = (int) $departmentId;
        }
        if (\is_string($type) && '' !== $type) {
            $data['ticket_type'] = $type;
        }

        $ticket = $this->ticketService->create($data);
        $this->customFields?->saveForTicket($ticket, $custom['values']);

        $this->addFlash('success', 'Ticket created successfully.');

        return $this->redirectToRoute('escalated.customer.tickets.show', [
            'reference' => $ticket->getReference(),
        ], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{reference}', name: 'show', methods: ['GET'])]
    public function show(string $reference): Response
    {
        $ticket = $this->ownTicket($reference);

        return $this->renderer->render('Escalated/Customer/Show', [
            'ticket' => $this->presenter->customer($ticket),
            'custom_fields' => $this->customFields?->forTicket($ticket) ?? [],
        ]);
    }

    #[Route('/{reference}/reply', name: 'reply', methods: ['POST'])]
    public function reply(string $reference, Request $request): Response
    {
        $ticket = $this->ownTicket($reference);

        $body = $this->payload($request)['body'] ?? null;
        if (!\is_string($body) || '' === trim($body)) {
            return $this->invalid($request, ['body' => 'The body field is required.'], ...$this->showRoute($ticket));
        }

        $this->ticketService->addReply($ticket, $this->requesterKey() ?? $this->user()->getUserIdentifier(), $body, false, $this->user()::class);

        $this->addFlash('success', 'Reply sent.');

        return $this->back($request, ...$this->showRoute($ticket));
    }

    #[Route('/{reference}/close', name: 'close', methods: ['POST'])]
    public function close(string $reference, Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        if (!$this->allowCustomerClose) {
            throw $this->createAccessDeniedException('Customers cannot close tickets.');
        }

        $ticket = $this->ownTicket($reference);

        try {
            $this->ticketService->close($ticket, $this->requesterKey());
        } catch (\InvalidArgumentException $e) {
            return $this->invalid($request, ['status' => $e->getMessage()], ...$this->showRoute($ticket));
        }

        $this->addFlash('success', 'Ticket closed.');

        return $this->back($request, ...$this->showRoute($ticket));
    }

    #[Route('/{reference}/reopen', name: 'reopen', methods: ['POST'])]
    public function reopenTicket(string $reference, Request $request): Response
    {
        $ticket = $this->ownTicket($reference);

        try {
            $this->ticketService->reopen($ticket, $this->requesterKey());
        } catch (\InvalidArgumentException $e) {
            return $this->invalid($request, ['status' => $e->getMessage()], ...$this->showRoute($ticket));
        }

        $this->addFlash('success', 'Ticket reopened.');

        return $this->back($request, ...$this->showRoute($ticket));
    }

    #[Route('/{reference}/rate', name: 'rate', methods: ['POST'])]
    public function rate(string $reference, Request $request): Response
    {
        $ticket = $this->ownTicket($reference);

        $payload = $this->payload($request);
        $comment = $payload['comment'] ?? null;
        $rating = $payload['rating'] ?? null;

        try {
            $this->satisfactionRatings->rate(
                $ticket,
                is_numeric($rating) ? (int) $rating : 0,
                \is_string($comment) && '' !== $comment ? $comment : null,
                $this->user()::class,
                (string) ($this->requesterKey() ?? $this->user()->getUserIdentifier()),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->invalid($request, ['rating' => $e->getMessage()], ...$this->showRoute($ticket));
        }

        $this->addFlash('success', 'Thanks for your feedback.');

        return $this->back($request, ...$this->showRoute($ticket));
    }

    private function ownTicket(string $reference): Ticket
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $ticket = $this->ticketService->find($reference);
        if (null === $ticket || null !== $ticket->getDeletedAt()) {
            throw $this->createNotFoundException('Ticket not found.');
        }

        if (!$this->isGranted(TicketRequesterVoter::ATTRIBUTE, $ticket)) {
            throw $this->createAccessDeniedException('You do not own this ticket.');
        }

        return $ticket;
    }

    private function requesterKey(): int|string|null
    {
        return $this->userKeys->resolve($this->user());
    }

    private function user(): UserInterface
    {
        $user = $this->getUser();
        if (!$user instanceof UserInterface) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function showRoute(Ticket $ticket): array
    {
        return ['escalated.customer.tickets.show', ['reference' => $ticket->getReference()]];
    }
}
