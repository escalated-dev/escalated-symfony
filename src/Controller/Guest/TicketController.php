<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Guest;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Controller\GuestVerificationEndpoints;
use Escalated\Symfony\Controller\TicketDeskController;
use Escalated\Symfony\Controller\UiController;
use Escalated\Symfony\Controller\Widget\WidgetController;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\GuestVerification;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Rendering\TicketPresenter;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Security\GuestAccess;
use Escalated\Symfony\Security\GuestEmailVerification;
use Escalated\Symfony\Security\GuestVerificationException;
use Escalated\Symfony\Service\GuestRateLimiter;
use Escalated\Symfony\Service\SatisfactionRatingService;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Widget\WidgetRequest;
use Escalated\Symfony\Widget\WidgetSettings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The browser guest portal: Escalated/Guest/Create and Guest/Show, as
 * escalated-laravel's Guest\TicketController and Guest\VerificationController.
 *
 * It runs on the verified guest access the widget uses (docs/guest-access.md):
 * a guest proves the mailbox with an emailed code before a ticket is opened or
 * looked up, and the ticket page's address carries an expiring grant from
 * Security\GuestAccess, never the permanent guest token. The portal is open
 * while guest tickets are allowed (`WidgetSettings::isAllowGuestTickets()`).
 */
#[Route('/guest', name: 'escalated.guest.')]
class TicketController extends UiController
{
    private const TOKEN = '[A-Za-z0-9_-]{1,4096}';

    public function __construct(
        private readonly TicketService $tickets,
        private readonly TicketPresenter $presenter,
        private readonly UiRendererInterface $renderer,
        private readonly GuestEmailVerification $verification,
        private readonly GuestAccess $guestAccess,
        private readonly GuestRateLimiter $rateLimiter,
        private readonly SatisfactionRatingService $ratings,
        private readonly WidgetSettings $settings,
        private readonly EntityManagerInterface $em,
        private readonly GuestVerificationEndpoints $endpoints,
    ) {
    }

    /**
     * Emails a code. Body `{email, purpose}`, purpose `ticket` or `lookup`.
     */
    #[Route('/verification', name: 'verification', methods: ['POST'])]
    public function verification(Request $request): JsonResponse
    {
        $this->assertOpen();
        if (null !== $limited = $this->throttle(GuestRateLimiter::REPLY, $request)) {
            return $limited;
        }

        $data = $this->payload($request);
        $purpose = \is_string($data['purpose'] ?? null) ? $data['purpose'] : '';
        if (!\in_array($purpose, [GuestVerification::PURPOSE_TICKET, GuestVerification::PURPOSE_LOOKUP], true)) {
            return WidgetController::invalid(new GuestVerificationException('purpose', 'The selected purpose is invalid.'));
        }

        return $this->endpoints->verification($request, $data);
    }

    /**
     * The verified guest's tickets with this reference, each with a fresh
     * grant (rotating any earlier one).
     */
    #[Route('/lookup', name: 'lookup', methods: ['POST'])]
    public function lookup(Request $request): JsonResponse
    {
        $this->assertOpen();
        if (null !== $limited = $this->throttle(GuestRateLimiter::REPLY, $request)) {
            return $limited;
        }

        return $this->endpoints->lookup($this->payload($request));
    }

    #[Route('/create', name: 'tickets.create', methods: ['GET'])]
    public function create(): Response
    {
        $this->assertOpen();

        return $this->private($this->renderer->render('Escalated/Guest/Create', [
            'departments' => $this->presenter->departments(),
            'priorities' => TicketDeskController::priorities(),
            'verification_url' => $this->generateUrl('escalated.guest.verification'),
            'lookup_url' => $this->generateUrl('escalated.guest.lookup'),
        ]));
    }

    #[Route('', name: 'tickets.store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->assertOpen();
        if (null !== $limited = $this->throttle(GuestRateLimiter::TICKET, $request)) {
            return $limited;
        }

        $data = $this->payload($request);
        $errors = [];
        $name = WidgetRequest::guestName($data);
        if (null === $name || mb_strlen($name) > 255) {
            $errors['guest_name'] = 'The name field is required.';
        }
        $email = self::email($data);
        if (null === $email) {
            $errors['guest_email'] = 'The email field must be a valid email address.';
        }
        $subject = \is_string($data['subject'] ?? null) ? trim($data['subject']) : '';
        if ('' === $subject || mb_strlen($subject) > 255) {
            $errors['subject'] = '' === $subject ? 'The subject field is required.' : 'The subject may not be greater than 255 characters.';
        }
        $description = \is_string($data['description'] ?? null) ? $data['description'] : '';
        if ('' === trim($description) || mb_strlen($description) > 65535) {
            $errors['description'] = 'The description field is required.';
        }
        $priority = $data['priority'] ?? null;
        if (null !== $priority && '' !== $priority && !\in_array($priority, TicketDeskController::priorities(), true)) {
            $errors['priority'] = 'The selected priority is invalid.';
        }
        $departmentId = $data['department_id'] ?? null;
        if (null !== $departmentId && '' !== $departmentId
            && (!is_numeric($departmentId) || null === $this->em->getRepository(Department::class)->find((int) $departmentId))) {
            $errors['department_id'] = 'The selected department is invalid.';
        }
        if ([] === $errors && null !== WidgetController::missingProof($data)) {
            $errors['verification_code'] = 'Verify your email: request a code and enter it.';
        }
        if ([] !== $errors) {
            return $this->invalid($request, $errors, 'escalated.guest.tickets.create');
        }

        try {
            $grant = $this->verification->consume(
                $data['verification_id'],
                $data['verification_code'],
                (string) $email,
                GuestVerification::PURPOSE_TICKET,
                function () use ($name, $email, $subject, $description, $priority, $departmentId): array {
                    $ticket = $this->tickets->create(array_filter([
                        'subject' => $subject,
                        'description' => $description,
                        'priority' => \is_string($priority) && '' !== $priority ? $priority : null,
                        'department_id' => null !== $departmentId && '' !== $departmentId ? (int) $departmentId : null,
                        'guest_name' => $name,
                        'guest_email' => $email,
                        'metadata' => ['source' => 'guest_portal'],
                    ], static fn (mixed $v): bool => null !== $v));

                    return $this->guestAccess->issue($ticket, (string) $email);
                },
            );
        } catch (GuestVerificationException $e) {
            return $this->invalid($request, [$e->field => $e->getMessage()], 'escalated.guest.tickets.create');
        }

        $this->addFlash('success', 'Your ticket has been submitted. Bookmark this page to follow it.');

        return $this->private($this->redirectToRoute('escalated.guest.tickets.show', ['token' => $grant['token']], Response::HTTP_SEE_OTHER));
    }

    #[Route('/{token}', name: 'tickets.show', requirements: ['token' => self::TOKEN], methods: ['GET'])]
    public function show(string $token): Response
    {
        $ticket = $this->ticketFor($token);

        return $this->private($this->renderer->render('Escalated/Guest/Show', [
            'ticket' => $this->presenter->customer($ticket, true),
            'token' => $token,
        ]));
    }

    #[Route('/{token}/reply', name: 'tickets.reply', requirements: ['token' => self::TOKEN], methods: ['POST'])]
    public function reply(string $token, Request $request): Response
    {
        if (null !== $limited = $this->throttle(GuestRateLimiter::REPLY, $request)) {
            return $limited;
        }
        $ticket = $this->ticketFor($token);
        $back = ['escalated.guest.tickets.show', ['token' => $token]];

        if (Ticket::STATUS_CLOSED === $ticket->getStatus()) {
            $this->addFlash('error', 'This ticket is closed. Open a new ticket to continue.');

            return $this->private($this->back($request, ...$back));
        }

        $body = $this->payload($request)['body'] ?? null;
        if (!\is_string($body) || '' === trim($body) || mb_strlen($body) > 65535) {
            return $this->private($this->invalid($request, ['body' => 'The body field is required.'], ...$back));
        }

        // The guest's own reply: no author, as Laravel stores it.
        $this->tickets->addReply($ticket, null, $body);
        $this->addFlash('success', 'Reply sent.');

        return $this->private($this->back($request, ...$back));
    }

    #[Route('/{token}/rate', name: 'tickets.rate', requirements: ['token' => self::TOKEN], methods: ['POST'])]
    public function rate(string $token, Request $request): Response
    {
        if (null !== $limited = $this->throttle(GuestRateLimiter::REPLY, $request)) {
            return $limited;
        }
        $ticket = $this->ticketFor($token);
        $back = ['escalated.guest.tickets.show', ['token' => $token]];

        $data = $this->payload($request);
        $rating = $data['rating'] ?? null;
        $comment = $data['comment'] ?? null;
        try {
            $this->ratings->rate($ticket, is_numeric($rating) ? (int) $rating : 0, \is_string($comment) && '' !== $comment ? $comment : null);
        } catch (\InvalidArgumentException $e) {
            return $this->private($this->invalid($request, ['rating' => $e->getMessage()], ...$back));
        }
        $this->addFlash('success', 'Thanks for your feedback.');

        return $this->private($this->back($request, ...$back));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function email(array $data): ?string
    {
        $email = WidgetRequest::guestEmail($data);

        return null !== $email && mb_strlen($email) <= 255 && false !== filter_var($email, \FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function ticketFor(string $token): Ticket
    {
        $this->assertOpen();
        $ticket = $this->guestAccess->resolve($token);
        if (null === $ticket || null !== $ticket->getDeletedAt()) {
            throw $this->createNotFoundException('Ticket not found.');
        }

        return $ticket;
    }

    private function assertOpen(): void
    {
        if (!$this->settings->isAllowGuestTickets()) {
            throw $this->createNotFoundException('Guest tickets are disabled.');
        }
    }

    /**
     * Guest pages carry a grant in their address and the guest's
     * correspondence: never cached, and no referrer hands the grant on.
     */
    private function private(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function throttle(string $scope, Request $request): ?JsonResponse
    {
        $retryAfter = $this->rateLimiter->attempt($scope, $request->getClientIp() ?? 'unknown');
        if (null === $retryAfter) {
            return null;
        }

        return WidgetRequest::error('Too many requests. Please try again later.', Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) $retryAfter]);
    }
}
