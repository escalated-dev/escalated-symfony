<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\ApiToken;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Service\ApiTokenService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin CRUD over API tokens. The plaintext token is returned exactly once,
 * flashed back to the create request; only its hash is ever stored.
 *
 * Mirrors the ApiToken admin surface in escalated-laravel
 * (Http\Controllers\Admin\ApiTokenController).
 */
#[Route('/admin/api-tokens', name: 'escalated.admin.api_tokens.')]
class ApiTokenController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UiRendererInterface $renderer,
        private readonly ApiTokenService $service,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $tokens = $this->em->getRepository(ApiToken::class)
            ->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC']);

        return $this->renderer->render('Escalated/Admin/ApiTokens/Index', [
            'tokens' => array_map([$this, 'serialize'], $tokens),
        ]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $payload = $this->payload($request);

        $name = trim((string) ($payload['name'] ?? ''));
        if ('' === $name) {
            $this->addFlash('error', 'A token name is required.');

            return $this->redirectToRoute('escalated.admin.api_tokens.index');
        }

        $userId = $payload['user_id'] ?? $this->currentUserId();
        if (null === $userId || '' === (string) $userId) {
            $this->addFlash('error', 'A user is required.');

            return $this->redirectToRoute('escalated.admin.api_tokens.index');
        }

        $result = $this->service->createToken(
            (string) $userId,
            $name,
            $this->abilities($payload),
            $this->expiresAt($payload),
        );

        // The plaintext token is surfaced exactly once, mirroring Laravel's
        // `plain_text_token` flash payload.
        $this->addFlash('success', 'API token created.');
        $this->addFlash('plain_text_token', $result['plainTextToken']);

        return $this->redirectToRoute('escalated.admin.api_tokens.index');
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH', 'PUT'])]
    public function update(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $token = $this->em->getRepository(ApiToken::class)->find($id);
        if (null === $token) {
            throw $this->createNotFoundException('API token not found.');
        }

        $payload = $this->payload($request);

        if (isset($payload['name']) && '' !== trim((string) $payload['name'])) {
            $token->setName(trim((string) $payload['name']));
        }
        if (array_key_exists('abilities', $payload)) {
            $token->setAbilities($this->abilities($payload));
        }

        $this->em->flush();

        $this->addFlash('success', 'Token updated.');

        return $this->redirectToRoute('escalated.admin.api_tokens.index');
    }

    #[Route('/{id}', name: 'destroy', methods: ['DELETE'])]
    public function destroy(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $token = $this->em->getRepository(ApiToken::class)->find($id);
        if (null === $token) {
            throw $this->createNotFoundException('API token not found.');
        }

        $this->em->remove($token);
        $this->em->flush();

        $this->addFlash('success', 'Token revoked.');

        return $this->redirectToRoute('escalated.admin.api_tokens.index');
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        if ('' !== ($json = (string) $request->getContent())
            && str_starts_with((string) $request->headers->get('Content-Type'), 'application/json')
        ) {
            $decoded = json_decode($json, true);
            if (\is_array($decoded)) {
                return $decoded;
            }
        }

        return $request->request->all();
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return string[]
     */
    private function abilities(array $payload): array
    {
        $raw = $payload['abilities'] ?? ['*'];
        if (!\is_array($raw)) {
            $raw = [$raw];
        }

        // Service re-sanitises; keep this permissive and let it enforce the set.
        return array_values(array_map('strval', $raw));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function expiresAt(array $payload): ?\DateTimeImmutable
    {
        $days = $payload['expires_in_days'] ?? null;
        if (null === $days || '' === $days) {
            return null;
        }

        $days = (int) $days;
        if ($days <= 0) {
            return null;
        }

        return new \DateTimeImmutable(sprintf('+%d days', $days));
    }

    private function currentUserId(): ?string
    {
        $user = $this->getUser();

        return null === $user ? null : $user->getUserIdentifier();
    }

    /** @return array<string, mixed> */
    private function serialize(ApiToken $token): array
    {
        return [
            'id' => $token->getId(),
            'name' => $token->getName(),
            'user_id' => $token->getUserId(),
            'abilities' => $token->getAbilities(),
            'last_used_at' => $token->getLastUsedAt()?->format(\DateTimeInterface::ATOM),
            'last_used_ip' => $token->getLastUsedIp(),
            'expires_at' => $token->getExpiresAt()?->format(\DateTimeInterface::ATOM),
            'is_expired' => $token->isExpired(),
            'created_at' => $token->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
