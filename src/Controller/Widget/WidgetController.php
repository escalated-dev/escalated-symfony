<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Widget;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Article;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Service\GuestRateLimiter;
use Escalated\Symfony\Service\KnowledgeBaseService;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Widget\WidgetMetadata;
use Escalated\Symfony\Widget\WidgetOrigins;
use Escalated\Symfony\Widget\WidgetRequest;
use Escalated\Symfony\Widget\WidgetSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/widget/api', name: 'escalated.widget.')]
class WidgetController extends AbstractController
{
    public function __construct(
        private readonly WidgetSettings $widgetSettings,
        private readonly TicketService $ticketService,
        private readonly GuestRateLimiter $guestRateLimiter,
        private readonly EntityManagerInterface $em,
        private readonly KnowledgeBaseService $knowledgeBase,
    ) {
    }

    #[Route('/config', name: 'config', methods: ['GET'])]
    public function config(Request $request): JsonResponse
    {
        if (!$this->widgetSettings->isEnabled()) {
            return WidgetRequest::error('Widget is disabled.', Response::HTTP_NOT_FOUND);
        }

        // CORS for other sites is WidgetCorsSubscriber's job: an origin that
        // is not allowed gets this answer without a grant, so the browser
        // withholds it from the page.
        // Top-level keys are what the shared widget reads; `data` is kept for
        // existing clients.
        return $this->json([
            'enabled' => true,
            'color' => $this->widgetSettings->getPrimaryColor(),
            'position' => $this->widgetSettings->getPosition(),
            'greeting' => $this->widgetSettings->getGreeting(),
            'departments' => array_map(
                static fn (Department $d): array => ['id' => $d->getId(), 'name' => $d->getName()],
                $this->em->getRepository(Department::class)->findBy(['isActive' => true], ['name' => 'ASC']),
            ),
            'kb_enabled' => $this->widgetSettings->isShowArticles(),
            'guest_tickets_enabled' => $this->widgetSettings->isAllowGuestTickets(),
            // No `guest_verification_required`: this backend has no
            // /verification or /lookup, so the widget must not ask for codes.
            'accepts_metadata' => true,
            // Status lookup by reference + email is not offered: without
            // mailbox verification it would hand correspondence to anyone
            // who claims the address. The widget hides its Status tab.
            'status_lookup_enabled' => false,
            'data' => $this->widgetSettings->toPublicConfig(),
        ]);
    }

    #[Route('/articles', name: 'articles.search', methods: ['GET'])]
    public function searchArticles(Request $request): JsonResponse
    {
        if (!$this->widgetSettings->isEnabled() || !$this->widgetSettings->isShowArticles()) {
            return WidgetRequest::error('Articles are not available.', Response::HTTP_NOT_FOUND);
        }

        $query = trim((string) $request->query->get('q', ''));
        if (mb_strlen($query) < 2 || mb_strlen($query) > 200) {
            return WidgetRequest::error('Search for 2 to 200 characters.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $articles = \array_slice(
            $this->knowledgeBase->searchArticles($query, Article::STATUS_PUBLISHED),
            0,
            max(1, $this->widgetSettings->getMaxArticlesShown()),
        );

        return $this->json(['articles' => array_map(static fn (Article $a): array => [
            'title' => $a->getTitle(),
            'slug' => $a->getSlug(),
            'excerpt' => self::excerpt((string) $a->getBody()),
        ], $articles)]);
    }

    #[Route('/articles/{slug}', name: 'articles.show', methods: ['GET'])]
    public function showArticle(string $slug): JsonResponse
    {
        if (!$this->widgetSettings->isEnabled() || !$this->widgetSettings->isShowArticles()) {
            return WidgetRequest::error('Articles are not available.', Response::HTTP_NOT_FOUND);
        }

        $article = $this->knowledgeBase->findPublishedBySlug($slug);
        if (null === $article) {
            return WidgetRequest::error('Article not found.', Response::HTTP_NOT_FOUND);
        }

        $this->knowledgeBase->recordView($article);

        return $this->json([
            'title' => $article->getTitle(),
            'slug' => $article->getSlug(),
            'body' => $article->getBody(),
            'category' => $article->getCategory()?->getName(),
        ]);
    }

    #[Route('/tickets', name: 'tickets.store', methods: ['POST'])]
    public function createTicket(Request $request): JsonResponse
    {
        if (!$this->widgetSettings->isEnabled()) {
            return WidgetRequest::error('Widget is disabled.', Response::HTTP_NOT_FOUND);
        }

        if (null !== $limited = $this->throttle(GuestRateLimiter::TICKET, $request)) {
            return $limited;
        }

        if (!$this->widgetSettings->isAllowGuestTickets()) {
            return WidgetRequest::error('Guest tickets are disabled.', Response::HTTP_FORBIDDEN);
        }

        $data = WidgetRequest::body($request);
        $name = WidgetRequest::guestName($data);
        $email = WidgetRequest::guestEmail($data);

        if (empty($data['subject']) || null === $name || null === $email) {
            return WidgetRequest::error('Subject, name, and email are required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!WidgetMetadata::isValid($data['metadata'] ?? null)) {
            return WidgetRequest::error('Metadata must be an object with at most 50 entries.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $department = WidgetRequest::department($this->em, $data);
        if (false === $department) {
            return WidgetRequest::error('The selected department is invalid.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Host parameters from the embedding page, sanitised; `source` stays
        // the server's.
        $origin = $request->attributes->get(WidgetOrigins::ATTRIBUTE);
        $metadata = WidgetMetadata::sanitize($data['metadata'] ?? null, \is_string($origin) ? $origin : null) ?? [];
        $metadata['source'] = 'widget';

        try {
            $ticket = $this->ticketService->create(array_filter([
                'subject' => $data['subject'],
                'description' => $data['description'] ?? null,
                'guest_name' => $name,
                'guest_email' => $email,
                'department_id' => $department?->getId(),
                'metadata' => $metadata,
            ], static fn ($v): bool => null !== $v));
        } catch (\Exception $e) {
            return WidgetRequest::error('Failed to create ticket.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $response = $this->json([
            'message' => 'Ticket created successfully.',
            'reference' => $ticket->getReference(),
            // The widget's name for the guest token (`Authorization: Bearer`).
            'guest_access_token' => $ticket->getGuestToken(),
            'data' => [
                'reference' => $ticket->getReference(),
                'guest_token' => $ticket->getGuestToken(),
            ],
        ], Response::HTTP_CREATED);

        return $response;
    }

    #[Route('/tickets/{reference}', name: 'tickets.show', methods: ['GET'])]
    public function showTicket(string $reference, Request $request): JsonResponse
    {
        if (!$this->widgetSettings->isEnabled()) {
            return WidgetRequest::error('Widget is disabled.', Response::HTTP_NOT_FOUND);
        }

        $guestToken = WidgetRequest::guestToken($request);
        if (null === $guestToken) {
            return WidgetRequest::error('Guest token required.', Response::HTTP_UNAUTHORIZED);
        }

        $ticket = $this->ticketService->find($reference);
        if (null === $ticket || !hash_equals((string) $ticket->getGuestToken(), $guestToken)) {
            return WidgetRequest::error('Ticket not found.', Response::HTTP_NOT_FOUND);
        }

        $repliesArray = [];
        foreach ($ticket->getPublicReplies() as $r) {
            $replyAttachments = [];
            foreach ($r->getAttachments() as $a) {
                $replyAttachments[] = $a->toArray();
            }

            $isAgent = null !== $r->getAuthorClass();
            $repliesArray[] = [
                'body' => $r->getBody(),
                'author' => $isAgent ? 'Support' : ($ticket->getGuestName() ?? 'You'),
                'is_agent' => $isAgent,
                'created_at' => $r->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'attachments' => $replyAttachments,
            ];
        }

        $ticketAttachments = [];
        foreach ($ticket->getAttachments() as $a) {
            $ticketAttachments[] = $a->toArray();
        }

        $status = [
            'reference' => $ticket->getReference(),
            'subject' => $ticket->getSubject(),
            'status' => $ticket->getStatus(),
            'status_label' => self::statusLabel($ticket->getStatus()),
            'created_at' => $ticket->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'department' => $ticket->getDepartment()?->getName(),
            'replies' => $repliesArray,
        ];

        $response = $this->json($status + [
            'data' => [
                'reference' => $ticket->getReference(),
                'subject' => $ticket->getSubject(),
                'status' => $ticket->getStatus(),
                'created_at' => $ticket->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'replies' => $repliesArray,
                'attachments' => $ticketAttachments,
            ],
        ]);

        return $response;
    }

    #[Route('/tickets/{reference}/replies', name: 'tickets.reply', methods: ['POST'])]
    public function replyToTicket(string $reference, Request $request): JsonResponse
    {
        if (!$this->widgetSettings->isEnabled()) {
            return WidgetRequest::error('Widget is disabled.', Response::HTTP_NOT_FOUND);
        }

        // Before the guest token check, so requests with a missing or wrong
        // token are counted too and tokens cannot be guessed at speed.
        if (null !== $limited = $this->throttle(GuestRateLimiter::REPLY, $request)) {
            return $limited;
        }

        $guestToken = WidgetRequest::guestToken($request);
        if (null === $guestToken) {
            return WidgetRequest::error('Guest token required.', Response::HTTP_UNAUTHORIZED);
        }

        $ticket = $this->ticketService->find($reference);
        if (null === $ticket || !hash_equals((string) $ticket->getGuestToken(), $guestToken)) {
            return WidgetRequest::error('Ticket not found.', Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        if (empty($data['body'])) {
            return WidgetRequest::error('Reply body is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $reply = $this->ticketService->addReply($ticket, 0, $data['body']);

        $response = $this->json([
            'data' => [
                'body' => $reply->getBody(),
                'created_at' => $reply->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ],
        ], Response::HTTP_CREATED);

        return $response;
    }

    private static function statusLabel(string $status): string
    {
        return Ticket::STATUS_LIVE === $status ? 'Live chat' : ucfirst(str_replace('_', ' ', $status));
    }

    private static function excerpt(string $body): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($body), \ENT_QUOTES | \ENT_HTML5)));

        return mb_strlen($text) > 150 ? rtrim(mb_substr($text, 0, 150)).'...' : $text;
    }

    /**
     * 429 with Retry-After when the client IP is over the guest limit for this
     * scope. See GuestRateLimiter for configuration and the trusted-proxy
     * requirement.
     */
    private function throttle(string $scope, Request $request): ?JsonResponse
    {
        $retryAfter = $this->guestRateLimiter->attempt($scope, $request->getClientIp() ?? 'unknown');
        if (null === $retryAfter) {
            return null;
        }

        return WidgetRequest::error(
            'Too many requests. Please try again later.',
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => (string) $retryAfter],
        );
    }
}
