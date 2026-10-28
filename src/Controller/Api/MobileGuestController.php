<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Api;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Controller\GuestVerificationEndpoints;
use Escalated\Symfony\Controller\Widget\WidgetController;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\GuestVerification;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Rendering\TicketPresenter;
use Escalated\Symfony\Security\GuestAccess;
use Escalated\Symfony\Security\GuestEmailVerification;
use Escalated\Symfony\Security\GuestVerificationException;
use Escalated\Symfony\Service\GuestRateLimiter;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Widget\WidgetRequest;
use Escalated\Symfony\Widget\WidgetSettings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * The mobile guest API (escalated-developer-context
 * domain-model/mobile-guest-api.md): a guest with no account files a ticket,
 * reads it, replies and gets back into it, on the same emailed codes, grants
 * and per-IP guest budgets as the widget and the browser guest portal.
 *
 * `{token}` is the guest grant from Security\GuestAccess. A bad, expired,
 * rotated or chat grant is a 404. Every answer is sent `Cache-Control:
 * private, no-store` and `Referrer-Policy: no-referrer`
 * (EventSubscriber\GuestPrivacyHeadersSubscriber).
 */
#[AsController]
#[Route('/api/v1/mobile/guest', name: 'escalated.api.mobile.guest.')]
final class MobileGuestController
{
    private const PRIORITIES = [Ticket::PRIORITY_LOW, Ticket::PRIORITY_MEDIUM, Ticket::PRIORITY_HIGH, Ticket::PRIORITY_URGENT, Ticket::PRIORITY_CRITICAL];

    public function __construct(
        private readonly GuestVerificationEndpoints $endpoints,
        private readonly GuestEmailVerification $verification,
        private readonly GuestAccess $guestAccess,
        private readonly GuestRateLimiter $rateLimiter,
        private readonly TicketService $tickets,
        private readonly TicketPresenter $presenter,
        private readonly WidgetSettings $settings,
        private readonly EntityManagerInterface $em,
        #[Autowire('%escalated.tickets.default_priority%')]
        private readonly string $defaultPriority = Ticket::PRIORITY_MEDIUM,
    ) {
    }

    /**
     * Emails a code. Body `{email, purpose}`, purpose `ticket`, `chat` or
     * `lookup`.
     */
    #[Route('/verification', name: 'verification', methods: ['POST'])]
    public function verification(Request $request): JsonResponse
    {
        if (null !== $limited = $this->throttle($request, GuestRateLimiter::TICKET)) {
            return $limited;
        }

        $data = self::payload($request);
        $purpose = $data['purpose'] ?? null;
        if (GuestVerification::PURPOSE_CHAT === $purpose && !$this->settings->isEnabled()) {
            return self::message('Live chat is not enabled.', Response::HTTP_FORBIDDEN);
        }
        if (\in_array($purpose, [GuestVerification::PURPOSE_TICKET, GuestVerification::PURPOSE_LOOKUP], true) && !$this->settings->isAllowGuestTickets()) {
            return self::message('Guest tickets are not enabled.', Response::HTTP_FORBIDDEN);
        }

        return $this->endpoints->verification($request, $data);
    }

    /**
     * Fresh grants for the verified guest's tickets with this reference.
     */
    #[Route('/lookup', name: 'lookup', methods: ['POST'])]
    public function lookup(Request $request): JsonResponse
    {
        if (null !== $limited = $this->throttle($request, GuestRateLimiter::REPLY)) {
            return $limited;
        }
        if (!$this->settings->isAllowGuestTickets()) {
            return self::message('Guest tickets are not enabled.', Response::HTTP_FORBIDDEN);
        }

        return $this->endpoints->lookup(self::payload($request));
    }

    #[Route('/tickets', name: 'tickets.store', methods: ['POST'])]
    public function store(Request $request): JsonResponse
    {
        if (null !== $limited = $this->throttle($request, GuestRateLimiter::TICKET)) {
            return $limited;
        }
        if (!$this->settings->isAllowGuestTickets()) {
            return self::message('Guest tickets are not enabled.', Response::HTTP_FORBIDDEN);
        }

        $data = self::payload($request);
        $errors = [];
        $name = self::string($data, 'name', 255, $errors);
        $email = self::email($data, $errors);
        $subject = self::string($data, 'subject', 255, $errors);
        $description = self::string($data, 'description', 65535, $errors);

        $id = $data['verification_id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            $errors['verification_id'] = 'The verification id field is required.';
        } elseif (!Uuid::isValid($id)) {
            $errors['verification_id'] = 'The verification id field must be a valid UUID.';
        }
        $code = $data['verification_code'] ?? null;
        if (!\is_scalar($code) || '' === (string) $code) {
            $errors['verification_code'] = 'The verification code field is required.';
        } elseif (mb_strlen((string) $code) > 16) {
            $errors['verification_code'] = 'The verification code field must not be greater than 16 characters.';
        }

        $priority = $data['priority'] ?? null;
        if (null !== $priority && '' !== $priority && !\in_array($priority, self::PRIORITIES, true)) {
            $errors['priority'] = 'The selected priority is invalid.';
        }
        $department = null;
        $departmentId = $data['department_id'] ?? null;
        if (null !== $departmentId && '' !== $departmentId) {
            $department = \is_int($departmentId) || (\is_string($departmentId) && ctype_digit($departmentId))
                ? $this->em->find(Department::class, (int) $departmentId)
                : null;
            if (!$department instanceof Department) {
                $errors['department_id'] = 'The selected department is invalid.';
            }
        }
        if ([] !== $request->files->all() || isset($data['attachments'])) {
            $errors['attachments'] = 'Attachments are not supported on this server.';
        }

        if ([] !== $errors) {
            return self::validation($errors);
        }

        try {
            [$ticket, $grant] = $this->verification->consume(
                $id,
                $code,
                (string) $email,
                GuestVerification::PURPOSE_TICKET,
                function () use ($name, $email, $subject, $description, $priority, $department): array {
                    $ticket = $this->tickets->create([
                        'subject' => (string) $subject,
                        'description' => (string) $description,
                        'priority' => \is_string($priority) && '' !== $priority ? $priority : $this->defaultPriority,
                        'department_id' => $department?->getId(),
                        'guest_name' => (string) $name,
                        'guest_email' => GuestEmailVerification::normalize($email),
                        'channel' => Ticket::CHANNEL_WEB,
                        'metadata' => ['source' => 'mobile'],
                    ]);

                    return [$ticket, $this->guestAccess->issue($ticket, (string) $email)];
                },
            );
        } catch (GuestVerificationException $e) {
            return WidgetController::invalid($e);
        }

        return new JsonResponse([
            'data' => $this->presenter->mobile($ticket, $grant['token'], false),
            'message' => 'Ticket created.',
        ], Response::HTTP_CREATED);
    }

    #[Route('/tickets/{token}', name: 'tickets.show', requirements: ['token' => '[^/]+'], methods: ['GET'])]
    public function show(string $token, Request $request): JsonResponse
    {
        if (null !== $limited = $this->throttle($request)) {
            return $limited;
        }
        $ticket = $this->ticketFor($token);
        if (null === $ticket) {
            return self::notFound();
        }

        return new JsonResponse(['data' => $this->presenter->mobile($ticket, $token)]);
    }

    #[Route('/tickets/{token}/replies', name: 'tickets.reply', requirements: ['token' => '[^/]+'], methods: ['POST'])]
    public function reply(string $token, Request $request): JsonResponse
    {
        if (null !== $limited = $this->throttle($request, GuestRateLimiter::REPLY)) {
            return $limited;
        }
        $ticket = $this->ticketFor($token);
        if (null === $ticket) {
            return self::notFound();
        }
        if (Ticket::STATUS_CLOSED === $ticket->getStatus()) {
            return self::message('This ticket is closed.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = self::payload($request);
        $errors = [];
        $body = $data['body'] ?? null;
        if (!\is_string($body) || '' === trim($body)) {
            $errors['body'] = 'The body field is required.';
        } elseif (mb_strlen($body) > 65535) {
            $errors['body'] = 'The body field must not be greater than 65535 characters.';
        }
        $email = self::email($data, $errors);
        if ([] !== $request->files->all() || isset($data['attachments'])) {
            $errors['attachments'] = 'Attachments are not supported on this server.';
        }
        if ([] !== $errors) {
            return self::validation($errors);
        }

        // A second factor on top of the grant, not a substitute for it.
        if (!hash_equals(GuestEmailVerification::normalize($ticket->getGuestEmail()), GuestEmailVerification::normalize($email))) {
            return self::message('The provided email does not match this ticket.', Response::HTTP_FORBIDDEN);
        }

        // The guest's own public reply: no author, as on the guest portal.
        $this->tickets->addReply($ticket, null, (string) $body);

        return new JsonResponse(['message' => 'Reply sent.'], Response::HTTP_CREATED);
    }

    private function ticketFor(string $token): ?Ticket
    {
        $ticket = $this->guestAccess->resolve($token);

        return null === $ticket || null !== $ticket->getDeletedAt() ? null : $ticket;
    }

    /**
     * The shared `requests` budget, then the route's own guest budget. Both
     * are per client IP and shared with the widget and the guest portal.
     */
    private function throttle(Request $request, ?string $scope = null): ?JsonResponse
    {
        $ip = $request->getClientIp() ?? 'unknown';
        foreach (array_filter([GuestRateLimiter::REQUEST, $scope]) as $bucket) {
            $retryAfter = $this->rateLimiter->attempt($bucket, $ip);
            if (null !== $retryAfter) {
                return WidgetRequest::error('Too many requests. Please try again later.', Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) $retryAfter]);
            }
        }

        return null;
    }

    /**
     * A JSON body, or the form fields of a form or multipart request.
     *
     * @return array<string, mixed>
     */
    private static function payload(Request $request): array
    {
        if (str_contains((string) $request->headers->get('Content-Type'), 'json')) {
            return WidgetRequest::body($request);
        }

        return $request->request->all();
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $errors
     */
    private static function string(array $data, string $field, int $max, array &$errors): ?string
    {
        $value = $data[$field] ?? null;
        if (!\is_string($value) || '' === trim($value)) {
            $errors[$field] = sprintf('The %s field is required.', $field);

            return null;
        }
        if (mb_strlen($value) > $max) {
            $errors[$field] = sprintf('The %s field must not be greater than %d characters.', $field, $max);

            return null;
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $errors
     */
    private static function email(array $data, array &$errors): ?string
    {
        $value = $data['email'] ?? null;
        $email = \is_string($value) ? trim($value) : '';
        if ('' === $email) {
            $errors['email'] = 'The email field is required.';

            return null;
        }
        if (mb_strlen($email) > 255 || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'The email field must be a valid email address.';

            return null;
        }

        return $email;
    }

    /**
     * 422 in the shape the widget guest routes use.
     *
     * @param array<string, string> $errors
     */
    private static function validation(array $errors): JsonResponse
    {
        $message = (string) reset($errors);

        return new JsonResponse([
            'error' => $message,
            'message' => $message,
            'errors' => array_map(static fn (string $error): array => [$error], $errors),
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private static function message(string $message, int $status): JsonResponse
    {
        return WidgetRequest::error($message, $status);
    }

    private static function notFound(): JsonResponse
    {
        return self::message('Ticket not found.', Response::HTTP_NOT_FOUND);
    }
}
