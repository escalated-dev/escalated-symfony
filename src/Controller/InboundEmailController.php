<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller;

use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Mail\Inbound\InboundEmailParser;
use Escalated\Symfony\Mail\Inbound\InboundRouter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Single ingress point for inbound-email webhooks.
 *
 * Dispatches the raw payload to the matching {@see InboundEmailParser}
 * (selected via the {@code ?adapter=...} query parameter or
 * {@code X-Escalated-Adapter} header), then resolves the parsed
 * message to a ticket via {@see InboundRouter}.
 *
 * Guarded by a constant-time shared-secret check on the
 * {@code X-Escalated-Inbound-Secret} header. The secret is passed
 * in via the bundle's container configuration — same value that
 * signs Reply-To addresses on outbound (symmetric).
 */
#[Route('/escalated/webhook/email', name: 'escalated.inbound_email.')]
final class InboundEmailController extends AbstractController
{
    /**
     * @param iterable<InboundEmailParser>  $parsers
     */
    public function __construct(
        private readonly InboundRouter $router,
        #[TaggedIterator('escalated.inbound_parser')]
        private readonly iterable $parsers,
        private readonly string $inboundSecret = '',
    ) {
    }

    #[Route('/inbound', name: 'inbound', methods: ['POST'])]
    public function inbound(Request $request): JsonResponse
    {
        if (! $this->verifySecret($request)) {
            return new JsonResponse(
                ['error' => 'missing or invalid inbound secret'],
                JsonResponse::HTTP_UNAUTHORIZED
            );
        }

        $adapter = (string) ($request->query->get('adapter') ?? $request->headers->get('X-Escalated-Adapter') ?? '');
        if ($adapter === '') {
            return new JsonResponse(['error' => 'missing adapter'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $parser = $this->findParser($adapter);
        if ($parser === null) {
            return new JsonResponse(
                ['error' => "unknown adapter: {$adapter}"],
                JsonResponse::HTTP_BAD_REQUEST
            );
        }

        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            return new JsonResponse(['error' => 'invalid json body'], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            $message = $parser->parse($payload);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'invalid payload'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $ticket = $this->router->resolveTicket($message);

        return new JsonResponse([
            'status' => $ticket instanceof Ticket ? 'matched' : 'unmatched',
            'ticket_id' => $ticket instanceof Ticket ? $ticket->getId() : null,
        ], JsonResponse::HTTP_ACCEPTED);
    }

    private function findParser(string $adapter): ?InboundEmailParser
    {
        foreach ($this->parsers as $parser) {
            if ($parser->name() === $adapter) {
                return $parser;
            }
        }

        return null;
    }

    private function verifySecret(Request $request): bool
    {
        if ($this->inboundSecret === '') {
            // Inbound signing not configured → disable the webhook
            // (prevents accidental unauthenticated routing).
            return false;
        }
        $provided = (string) ($request->headers->get('X-Escalated-Inbound-Secret') ?? '');
        if ($provided === '') {
            return false;
        }

        return hash_equals($this->inboundSecret, $provided);
    }
}
