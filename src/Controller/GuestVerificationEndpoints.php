<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Controller\Widget\WidgetController;
use Escalated\Symfony\Entity\GuestVerification;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Security\GuestAccess;
use Escalated\Symfony\Security\GuestCodeBudgetException;
use Escalated\Symfony\Security\GuestEmailVerification;
use Escalated\Symfony\Security\GuestVerificationException;
use Escalated\Symfony\Widget\WidgetRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The `verification` and `lookup` handlers every guest surface mounts: the
 * widget API, the browser guest portal and the mobile guest API
 * (docs/guest-access.md). Each surface keeps its own gating (enabled
 * switches, rate limits, allowed purposes) and calls these for the work, so
 * a code or a grant from one surface is good on the others.
 */
final class GuestVerificationEndpoints
{
    public function __construct(
        private readonly GuestEmailVerification $verification,
        private readonly GuestAccess $guestAccess,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Emails a code for `{email, purpose}`: 202 with the verification id, 422
     * for a bad email or purpose, 429 when the mailbox is over its budget.
     *
     * @param array<string, mixed> $data
     */
    public function verification(Request $request, array $data, string $message = 'Check your email for a verification code.'): JsonResponse
    {
        try {
            $id = $this->verification->challenge(
                \is_string($data['email'] ?? null) ? $data['email'] : '',
                \is_string($data['purpose'] ?? null) ? $data['purpose'] : '',
                $request->getClientIp() ?? 'unknown',
            );
        } catch (GuestVerificationException $e) {
            return WidgetController::invalid($e);
        } catch (GuestCodeBudgetException $e) {
            return WidgetController::noStore(WidgetRequest::error($e->getMessage(), Response::HTTP_TOO_MANY_REQUESTS, ['Retry-After' => (string) $e->retryAfter]));
        }

        return WidgetController::noStore(new JsonResponse([
            'verification_id' => $id,
            'expires_in' => GuestEmailVerification::CODE_TTL_SECONDS,
            'message' => $message,
        ], Response::HTTP_ACCEPTED));
    }

    /**
     * The verified guest's tickets with this reference (at most 20), each
     * with a fresh grant that rotates any earlier one. The `lookup` proof is
     * consumed even when nothing matches.
     *
     * @param array<string, mixed> $data
     */
    public function lookup(array $data): JsonResponse
    {
        $email = WidgetRequest::guestEmail($data);
        $reference = \is_scalar($data['reference'] ?? null) ? trim((string) $data['reference']) : '';
        if (null === $email || '' === $reference || mb_strlen($reference) > 191) {
            return WidgetController::invalid(new GuestVerificationException(null === $email ? 'email' : 'reference', 'Email and reference are required.'));
        }
        if (null !== $missing = WidgetController::missingProof($data)) {
            return $missing;
        }

        try {
            $matches = $this->verification->consume(
                $data['verification_id'],
                $data['verification_code'],
                $email,
                GuestVerification::PURPOSE_LOOKUP,
                function () use ($email, $reference): array {
                    $normalized = GuestEmailVerification::normalize($email);
                    $found = [];
                    foreach ($this->em->getRepository(Ticket::class)->findBy(['reference' => $reference], ['id' => 'DESC'], 20) as $ticket) {
                        if (null === $ticket->getGuestEmail() || GuestEmailVerification::normalize($ticket->getGuestEmail()) !== $normalized) {
                            continue;
                        }
                        $grant = $this->guestAccess->issue($ticket, $email);
                        $found[] = [
                            'reference' => $ticket->getReference(),
                            'subject' => $ticket->getSubject(),
                            'guest_access_token' => $grant['token'],
                            'expires_at' => $grant['expires_at']->format(\DateTimeInterface::ATOM),
                        ];
                    }

                    return $found;
                },
            );
        } catch (GuestVerificationException $e) {
            return WidgetController::invalid($e);
        }

        return WidgetController::noStore(new JsonResponse(['data' => $matches]));
    }
}
