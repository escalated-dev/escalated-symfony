<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Ui;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Service\TicketSubjectService;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * The sidebar's Related card reads `ticket.subjects` on the agent and admin
 * ticket pages. escalated-laravel's show pages never loaded them; here both
 * pages send the ticket's subjects serialised exactly as the JSON API's
 * ticket detail does. The API detail itself left them out: it passes its
 * group as a string ('ticket:detail'), and TicketNormalizer read only the
 * first character of it when deciding whether it serialised a detail.
 */
final class TicketSubjectsOnShowPagesTest extends EscalatedWebTestCase
{
    private KernelBrowser $client;
    private TestUser $admin;
    private TestUser $agent;
    private Ticket $ticket;

    protected function setUp(): void
    {
        $this->client = static::clientWithSchema(['escalated' => ['user_class' => TestUser::class]]);
        $em = static::entityManager();
        $this->admin = new TestUser('admin@example.com', ['ROLE_ESCALATED_ADMIN']);
        $this->agent = new TestUser('agent@example.com');
        $em->persist($this->admin);
        $em->persist($this->agent);
        $em->flush();
        $em->persist((new AgentProfile())->setUserId((int) $this->agent->getId()));
        $em->flush();

        $tickets = static::getContainer()->get(TicketService::class);
        \assert($tickets instanceof TicketService);
        $this->ticket = $tickets->create(['subject' => 'Order never arrived', 'guest_name' => 'Gina', 'guest_email' => 'gina@example.com']);

        $subjects = static::getContainer()->get(TicketSubjectService::class);
        \assert($subjects instanceof TicketSubjectService);
        $subjects->attach($this->ticket, 'order', 'SO-1042', 'primary', false);
        $subjects->attach($this->ticket, 'customer', 77, null, false);
    }

    public function testAgentAndAdminShowPagesSendTheSubjectsAsTheApiDoes(): void
    {
        $api = $this->apiSubjects();
        self::assertCount(2, $api);
        self::assertSame(['order', 'customer'], array_column($api, 'type'));

        $this->client->loginUser($this->agent);
        $agentPage = $this->visit('/support/agent/tickets/'.$this->ticket->getReference());
        self::assertSame('Escalated/Agent/TicketShow', $agentPage['component']);
        self::assertSame($api, $agentPage['props']['ticket']['subjects']);

        $this->client->loginUser($this->admin);
        $adminPage = $this->visit('/support/admin/tickets/'.$this->ticket->getReference());
        self::assertSame('Escalated/Admin/Tickets/Show', $adminPage['component']);
        self::assertSame($api, $adminPage['props']['ticket']['subjects']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function apiSubjects(): array
    {
        $serializer = static::getContainer()->get('serializer');
        \assert($serializer instanceof SerializerInterface);
        $data = json_decode($serializer->serialize($this->ticket, 'json', ['groups' => 'ticket:detail']), true);
        self::assertIsArray($data);

        return $data['subjects'];
    }

    /**
     * @return array<string, mixed>
     */
    private function visit(string $uri): array
    {
        $this->client->request('GET', $uri, server: ['HTTP_X_INERTIA' => 'true', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml']);
        self::assertResponseIsSuccessful();
        $page = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($page);

        return $page;
    }
}
