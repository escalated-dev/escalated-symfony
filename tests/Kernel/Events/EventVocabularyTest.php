<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Events;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\Tag;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\Webhook;
use Escalated\Symfony\Entity\Workflow;
use Escalated\Symfony\Event\EventNames;
use Escalated\Symfony\Http\WebhookHttpClientInterface;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Service\WebhookDispatcher;
use Escalated\Symfony\Service\WorkflowEngine;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Escalated\Symfony\Tests\Service\RecordingWebhookHttpClient;
use Escalated\Symfony\Widget\WidgetSettings;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * decisions/2026-10-21-event-vocabulary.md: webhooks and workflows emit the
 * canonical names only, and keep reading the legacy names on stored data.
 *
 * Before: tags were announced to workflows as `ticket.tagged` (so a
 * `ticket.tag_added` workflow never ran), resolved/closed tickets fired no
 * `ticket.resolved`/`ticket.closed` workflow, a webhook subscribed to a
 * legacy name such as `internal_note.added` never received anything, and live
 * chat reached neither webhooks nor workflows.
 */
final class EventVocabularyTest extends EscalatedWebTestCase
{
    private KernelBrowser $client;

    private RecordingWebhookHttpClient $http;

    protected function setUp(): void
    {
        $this->client = static::clientWithSchema();
        $this->http = RecordingWebhookHttpClient::alwaysReturns(200, 'ok');
        static::getContainer()->set(WebhookHttpClientInterface::class, $this->http);

        $settings = static::getContainer()->get(WidgetSettings::class);
        \assert($settings instanceof WidgetSettings);
        $settings->setEnabled(true);
    }

    public function testATagAddedWorkflowFiresAndALegacyTicketTaggedOneStillDoes(): void
    {
        $tag = $this->tag();
        $ticket = $this->ticket();
        $this->workflow('ticket.tag_added', 'canonical');
        $this->workflow('ticket.tagged', 'legacy');

        $this->tickets()->addTags($ticket, [(int) $tag->getId()]);

        self::assertSame(['canonical', 'legacy'], $this->notes($ticket));
    }

    public function testATagRemovedWorkflowFiresOnRemovalOnly(): void
    {
        $tag = $this->tag();
        $ticket = $this->ticket();
        $this->workflow('ticket.tag_removed', 'removed');

        $this->tickets()->addTags($ticket, [(int) $tag->getId()]);
        self::assertSame([], $this->notes($ticket));

        $this->tickets()->removeTags($ticket, [(int) $tag->getId()]);
        self::assertSame(['removed'], $this->notes($ticket));
    }

    public function testResolvedAndClosedWorkflowsFire(): void
    {
        $ticket = $this->ticket();
        $this->workflow('ticket.resolved', 'resolved');
        $this->workflow('ticket.closed', 'closed');

        $this->tickets()->changeStatus($ticket, Ticket::STATUS_RESOLVED);
        self::assertSame(['resolved'], $this->notes($ticket));

        $this->tickets()->changeStatus($ticket, Ticket::STATUS_CLOSED);
        self::assertSame(['resolved', 'closed'], $this->notes($ticket));
    }

    public function testAWebhookSubscribedToLegacyNamesReceivesTheCanonicalEvents(): void
    {
        $tag = $this->tag();
        $ticket = $this->ticket();
        $this->webhook(['internal_note.added', 'ticket.tagged', 'ticket.untagged', 'ticket.replied']);

        $this->tickets()->addReply($ticket, 1, 'Public answer');
        $this->tickets()->addReply($ticket, 1, 'Agents only', true);
        $this->tickets()->addTags($ticket, [(int) $tag->getId()]);
        $this->tickets()->removeTags($ticket, [(int) $tag->getId()]);
        $this->flushWebhooks();

        self::assertSame(['reply.created', 'note.created', 'ticket.tag_added', 'ticket.tag_removed'], $this->deliveredEvents());
    }

    public function testTheWebhookAdminStoresAndShowsCanonicalNamesAndOffersOnlyEmittedOnes(): void
    {
        $this->loginAdmin();

        $this->client->jsonRequest('POST', '/support/admin/webhooks', [
            'url' => 'https://93.184.215.14/hooks/escalated',
            'events' => ['internal_note.added', 'chat.session_started', 'note.created', 'ticket.created'],
            'active' => true,
        ]);
        self::assertResponseRedirects();

        $em = static::entityManager();
        $em->clear();
        $webhook = $em->getRepository(Webhook::class)->findOneBy([]);
        self::assertNotNull($webhook);
        self::assertSame(['note.created', 'chat.started', 'ticket.created'], $webhook->getEvents());

        $legacy = (new Webhook())->setUrl('https://93.184.215.14/legacy')->setEvents(['ticket.replied', 'tag.removed']);
        $em->persist($legacy);
        $em->flush();

        $this->client->request('GET', '/support/admin/webhooks/'.$legacy->getId().'/edit', server: ['HTTP_X_INERTIA' => 'true']);
        self::assertResponseIsSuccessful();
        $props = $this->props();
        self::assertSame(['reply.created', 'ticket.tag_removed'], $props['webhook']['events']);
        self::assertSame(EventNames::EMITTED, $props['availableEvents']);
        foreach (array_keys(EventNames::LEGACY) as $legacyName) {
            self::assertNotContains($legacyName, $props['availableEvents']);
        }
    }

    public function testWorkflowTriggersAreTheEmittedCanonicalNames(): void
    {
        self::assertSame(EventNames::EMITTED, WorkflowEngine::TRIGGER_EVENTS);
    }

    public function testLiveChatReachesWebhooksAndWorkflowsUnderCanonicalNames(): void
    {
        $this->webhook(['chat.started', 'chat.assigned', 'chat.message', 'chat.ended']);
        $this->workflow('chat.started', 'started', subject: 'Live Chat');
        $this->workflow('chat.session_started', 'legacy started', subject: 'Live Chat');
        $this->workflow('chat.assigned', 'assigned', subject: 'Live Chat');
        $this->workflow('chat.message', 'message', subject: 'Live Chat');
        $this->workflow('chat.session_ended', 'legacy ended', subject: 'Live Chat');

        $this->client->request('POST', '/support/widget/api/chat/start', server: [
            'REMOTE_ADDR' => '2001:db8::77',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: (string) json_encode(['name' => 'Ada', 'email' => 'ada@example.com', 'message' => 'Hello']));
        self::assertResponseStatusCodeSame(201);
        $started = json_decode((string) $this->client->getResponse()->getContent(), true);
        $token = (string) $started['id'];
        $chatTicket = static::entityManager()->getRepository(Ticket::class)->findOneBy(['reference' => $started['reference']]);
        self::assertNotNull($chatTicket);
        $ticketId = (int) $chatTicket->getId();

        $agent = $this->loginAgent();
        $this->client->jsonRequest('POST', "/support/agent/chat/sessions/{$ticketId}/accept");
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('POST', "/support/agent/chat/sessions/{$ticketId}/message", ['body' => 'How can I help?']);
        self::assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', "/support/agent/chat/sessions/{$ticketId}/end");
        self::assertResponseIsSuccessful();

        self::assertSame(['chat.started', 'chat.assigned', 'chat.message', 'chat.ended'], $this->deliveredEvents());
        foreach ($this->http->calls as $call) {
            self::assertStringNotContainsString($token, $call['body'], 'the visitor chat token leaked into a webhook');
            $payload = json_decode($call['body'], true)['payload'];
            self::assertSame($ticketId, $payload['ticket']['id']);
            self::assertIsInt($payload['chat_session_id']);
        }
        $assigned = json_decode($this->http->calls[1]['body'], true)['payload'];
        self::assertSame($agent->getId(), $assigned['agent_id']);

        $ticket = static::entityManager()->getRepository(Ticket::class)->find($ticketId);
        self::assertNotNull($ticket);
        self::assertSame(['started', 'legacy started', 'assigned', 'message', 'legacy ended'], $this->notes($ticket));
    }

    /**
     * @param list<string> $events
     */
    private function webhook(array $events): void
    {
        $webhook = (new Webhook())->setUrl('https://93.184.215.14/hooks')->setEvents($events)->setActive(true);
        $em = static::entityManager();
        $em->persist($webhook);
        $em->flush();
    }

    /** @return list<string> */
    private function deliveredEvents(): array
    {
        return array_map(
            static fn (array $call): string => json_decode($call['body'], true)['event'],
            $this->http->calls,
        );
    }

    private function flushWebhooks(): void
    {
        $dispatcher = static::getContainer()->get(WebhookDispatcher::class);
        \assert($dispatcher instanceof WebhookDispatcher);
        $dispatcher->flushPending();
    }

    private function workflow(string $trigger, string $note, string $subject = 'fire'): void
    {
        static $position = 0;
        $workflow = (new Workflow())
            ->setName('On '.$trigger)
            ->setTriggerEvent($trigger)
            ->setPosition(++$position)
            ->setConditions(['all' => [['field' => 'subject', 'operator' => 'contains', 'value' => $subject]]])
            ->setActions([['type' => 'add_note', 'value' => $note]]);
        $em = static::entityManager();
        $em->persist($workflow);
        $em->flush();
    }

    /**
     * The notes workflows wrote on a ticket, oldest first.
     *
     * @return list<string>
     */
    private function notes(Ticket $ticket): array
    {
        $rows = static::entityManager()->getConnection()->fetchFirstColumn(
            'SELECT body FROM escalated_replies WHERE ticket_id = ? AND is_internal_note = ? AND author_id IS NULL ORDER BY id',
            [$ticket->getId(), true],
            [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::BOOLEAN],
        );

        return array_values(array_map('strval', $rows));
    }

    private function tag(): Tag
    {
        $tag = (new Tag())->setName('Hardware')->setSlug('hardware-'.bin2hex(random_bytes(3)));
        $em = static::entityManager();
        $em->persist($tag);
        $em->flush();

        return $tag;
    }

    private function ticket(): Ticket
    {
        $ticket = (new Ticket())
            ->setSubject('Printer on fire')
            ->setStatus(Ticket::STATUS_OPEN)
            ->setReference('ESC-V'.bin2hex(random_bytes(4)));
        $em = static::entityManager();
        $em->persist($ticket);
        $em->flush();

        return $ticket;
    }

    private function tickets(): TicketService
    {
        $service = static::getContainer()->get(TicketService::class);
        \assert($service instanceof TicketService);

        return $service;
    }

    private function loginAdmin(): void
    {
        $admin = new TestUser('admin@example.com', ['ROLE_ESCALATED_ADMIN']);
        $em = static::entityManager();
        $em->persist($admin);
        $em->flush();
        $this->client->loginUser($admin);
    }

    private function loginAgent(): TestUser
    {
        $agent = new TestUser('agent@example.com');
        $em = static::entityManager();
        $em->persist($agent);
        $em->flush();
        $profile = new AgentProfile();
        $profile->setUserId($agent->getId());
        $em->persist($profile);
        $em->flush();
        $this->client->loginUser($agent);

        return $agent;
    }

    /** @return array<string, mixed> */
    private function props(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body, 'expected an Inertia JSON response');

        return $body['props'] ?? [];
    }
}
