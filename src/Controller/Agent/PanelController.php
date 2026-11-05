<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Agent;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Controller\UiController;
use Escalated\Symfony\Entity\AgentNotification;
use Escalated\Symfony\Entity\Article;
use Escalated\Symfony\Security\UserKeyResolver;
use Escalated\Symfony\Service\AgentPanel;
use Escalated\Symfony\Service\KnowledgeBaseService;
use Escalated\Symfony\Service\KnowledgeBaseSettings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The agent panel's own data routes (ADR 2026-11-05-agent-panel-data): the
 * agent's status, knowledge-base search and the notifications feed. Each acts
 * for the signed-in agent only.
 */
#[Route('/agent', name: 'escalated.agent.')]
class PanelController extends UiController
{
    private const KB_DEFAULT_LIMIT = 10;
    private const KB_MAX_LIMIT = 20;

    public function __construct(
        private readonly AgentPanel $panel,
        private readonly UserKeyResolver $userKeys,
        private readonly EntityManagerInterface $em,
        private readonly KnowledgeBaseService $knowledgeBase,
        private readonly KnowledgeBaseSettings $knowledgeBaseSettings,
    ) {
    }

    /** PUT/POST /agent/status: any agent, light agents included, sets their own. */
    #[Route('/status', name: 'status', methods: ['PUT', 'POST'])]
    public function status(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $status = $this->payload($request)['status'] ?? null;
        if (!\is_string($status) || !\in_array($status, AgentPanel::STATUSES, true)) {
            return $this->invalid($request, ['status' => 'The selected status is invalid ('.implode(', ', AgentPanel::STATUSES).').'], 'escalated.agent.tickets.index');
        }
        $this->panel->setStatus($this->me(), $status);

        if ($request->headers->has('X-Inertia') || 'json' !== $request->getPreferredFormat()) {
            return $this->back($request, 'escalated.agent.tickets.index');
        }

        return new JsonResponse(['agent_status' => $status]);
    }

    /** GET /agent/kb/search?q=: published articles, title matches first, then the newest. */
    #[Route('/kb/search', name: 'kb.search', methods: ['GET'])]
    public function kbSearch(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');
        if (!$this->knowledgeBaseSettings->isEnabled()) {
            throw $this->createNotFoundException();
        }

        $q = trim((string) $request->query->get('q', ''));
        if (mb_strlen($q) < 2 || mb_strlen($q) > 200) {
            return $this->json(['message' => 'The q field must be between 2 and 200 characters.', 'errors' => ['q' => ['The q field must be between 2 and 200 characters.']]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $limit = $request->query->get('limit');
        if (null !== $limit && '' !== $limit && (!ctype_digit((string) $limit) || (int) $limit < 1 || (int) $limit > self::KB_MAX_LIMIT)) {
            $message = sprintf('The limit field must be between 1 and %d.', self::KB_MAX_LIMIT);

            return $this->json(['message' => $message, 'errors' => ['limit' => [$message]]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $articles = $this->knowledgeBase->searchForAgents($q, null === $limit || '' === $limit ? self::KB_DEFAULT_LIMIT : (int) $limit);

        return $this->json(['data' => array_map(fn (Article $article): array => [
            'id' => $article->getId(),
            'title' => $article->getTitle(),
            'excerpt' => self::excerpt($article->getBody()),
            'url' => $this->generateUrl('escalated.customer.kb.show', ['slug' => $article->getSlug()], UrlGeneratorInterface::ABSOLUTE_URL),
            'category' => null !== $article->getCategory() ? ['id' => $article->getCategory()->getId(), 'name' => $article->getCategory()->getName()] : null,
            'updated_at' => $article->getUpdatedAt()->format(\DATE_ATOM),
        ], $articles)]);
    }

    /** GET /agent/notifications */
    #[Route('/notifications', name: 'notifications.index', methods: ['GET'])]
    public function notifications(Request $request): Response
    {
        $me = $this->feedUser();
        $page = $request->query->get('page');
        $perPage = $request->query->get('per_page');
        if (null !== $page && ('' === $page || !ctype_digit((string) $page) || (int) $page < 1)) {
            return $this->json(['message' => 'The page field must be at least 1.', 'errors' => ['page' => ['The page field must be at least 1.']]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (null !== $perPage && ('' === $perPage || !ctype_digit((string) $perPage) || (int) $perPage < 1 || (int) $perPage > AgentPanel::NOTIFICATIONS_MAX_PER_PAGE)) {
            $message = sprintf('The per page field must be between 1 and %d.', AgentPanel::NOTIFICATIONS_MAX_PER_PAGE);

            return $this->json(['message' => $message, 'errors' => ['per_page' => [$message]]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $current = null === $page ? 1 : (int) $page;
        $size = null === $perPage ? AgentPanel::NOTIFICATIONS_PER_PAGE : (int) $perPage;
        $unread = \in_array(strtolower((string) $request->query->get('unread', '')), ['1', 'true'], true);

        $base = $this->em->createQueryBuilder()->from(AgentNotification::class, 'n')
            ->where('n.userId = :me')->setParameter('me', $me);
        if ($unread) {
            $base->andWhere('n.readAt IS NULL');
        }
        $total = (int) (clone $base)->select('COUNT(n.id)')->getQuery()->getSingleScalarResult();
        $rows = (clone $base)->select('n')
            ->orderBy('n.createdAt', 'DESC')->addOrderBy('n.id', 'DESC')
            ->setFirstResult(($current - 1) * $size)->setMaxResults($size)
            ->getQuery()->getResult();

        return $this->json([
            'data' => $this->panel->present($rows),
            'meta' => [
                'current_page' => $current,
                'last_page' => max(1, (int) ceil($total / $size)),
                'per_page' => $size,
                'total' => $total,
            ],
            'unread_count' => $this->panel->unreadCount($me),
        ]);
    }

    /** POST /agent/notifications/read-all (declared before /{id}/read). */
    #[Route('/notifications/read-all', name: 'notifications.read-all', methods: ['POST'], priority: 10)]
    public function readAll(): Response
    {
        $me = $this->feedUser();
        $this->em->createQueryBuilder()->update(AgentNotification::class, 'n')
            ->set('n.readAt', ':now')
            ->where('n.userId = :me')->andWhere('n.readAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable(), 'datetime_immutable')
            ->setParameter('me', $me)
            ->getQuery()->execute();

        return $this->json(['unread_count' => 0]);
    }

    /** POST /agent/notifications/{id}/read */
    #[Route('/notifications/{id}/read', name: 'notifications.read', methods: ['POST'])]
    public function read(string $id): Response
    {
        $me = $this->feedUser();
        $row = $this->em->getRepository(AgentNotification::class)->findOneBy(['id' => $id, 'userId' => $me]);
        if (null === $row) {
            throw $this->createNotFoundException();
        }
        $row->markRead();
        $this->em->flush();

        return $this->json(['unread_count' => $this->panel->unreadCount($me)]);
    }

    private function feedUser(): int|string
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');
        if (!$this->panel->notificationsAvailable()) {
            throw $this->createNotFoundException();
        }

        return $this->me();
    }

    private function me(): int|string
    {
        $user = $this->getUser();
        $id = $user instanceof UserInterface ? $this->userKeys->resolve($user) : null;

        return $id ?? throw $this->createAccessDeniedException();
    }

    private static function excerpt(?string $html): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $html), \ENT_QUOTES | \ENT_HTML5)));

        return mb_strlen($text) > 160 ? rtrim(mb_substr($text, 0, 160)).'...' : $text;
    }
}
