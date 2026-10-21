<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Ui;

use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\SatisfactionRating;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Escalated\Symfony\Tests\Kernel\ObtainsGuestProof;
use Escalated\Symfony\Widget\WidgetSettings;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The browser guest portal (Escalated/Guest/Create and Guest/Show), on the
 * verified guest access the widget uses: an emailed code before a ticket is
 * opened or looked up, and an expiring grant in the ticket page's address.
 * Bodies are what the shared pages post.
 */
final class GuestPagesTest extends EscalatedWebTestCase
{
    use ObtainsGuestProof;

    private const API = '/support/guest';

    private KernelBrowser $client;
    private Department $billing;

    protected function setUp(): void
    {
        $this->client = static::clientWithSchema(['escalated' => ['user_class' => TestUser::class]]);
        $em = static::entityManager();
        $this->billing = (new Department())->setName('Billing')->setSlug('billing');
        $em->persist($this->billing);
        $em->flush();
    }

    public function testCreatePageGetsTheVerificationAndLookupUrls(): void
    {
        $page = $this->visit(self::API.'/create');

        self::assertSame('Escalated/Guest/Create', $page['component']);
        self::assertSame([
            'departments' => [['id' => $this->billing->getId(), 'name' => 'Billing']],
            'priorities' => ['low', 'medium', 'high', 'urgent', 'critical'],
            'verification_url' => '/support/guest/verification',
            'lookup_url' => '/support/guest/lookup',
        ], $page['props']);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testAVerifiedGuestOpensATicketAndFollowsItByGrant(): void
    {
        $proof = self::guestProof($this->client, 'gina@example.com', 'ticket', self::API);
        $this->post(self::API, $this->ticketBody() + $proof);

        $ticket = $this->onlyTicket();
        self::assertSame('gina@example.com', $ticket->getGuestEmail());
        self::assertSame('Gina', $ticket->getGuestName());
        self::assertSame('high', $ticket->getPriority());
        self::assertSame($this->billing->getId(), $ticket->getDepartment()?->getId());
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertResponseStatusCodeSame(303);
        self::assertMatchesRegularExpression('#^/support/guest/[A-Za-z0-9_-]+$#', $location);
        $token = substr($location, \strlen(self::API) + 1);
        self::assertNotSame($ticket->getGuestToken(), $token, 'the address carries a grant, not the permanent token');

        $tickets = static::getContainer()->get(TicketService::class);
        $tickets->addReply($ticket, 1, 'We are on it', false, TestUser::class);
        $tickets->addReply($ticket, 1, 'Staff only', true, TestUser::class);

        $page = $this->visit($location);
        self::assertSame('Escalated/Guest/Show', $page['component']);
        self::assertSame($token, $page['props']['token']);
        $t = $page['props']['ticket'];
        self::assertSame(['reference', 'subject', 'description', 'status', 'priority', 'channel', 'department', 'attachments', 'replies', 'satisfaction_rating', 'resolved_at', 'closed_at', 'created_at', 'updated_at', 'guest_access_expires_at'], array_keys($t));
        self::assertSame(['We are on it'], array_column($t['replies'], 'body'));
        foreach (['id', 'body', 'is_internal_note', 'is_pinned', 'author', 'attachments', 'created_at'] as $key) {
            self::assertArrayHasKey($key, $t['replies'][0]);
        }
        self::assertSame(['name' => 'Billing'], $t['department']);
        self::assertNotNull($t['guest_access_expires_at']);
        self::assertSame('no-referrer', $this->client->getResponse()->headers->get('Referrer-Policy'));

        $this->post($location.'/reply', ['body' => 'Thanks!', 'attachments' => []], ['HTTP_REFERER' => $location]);
        self::assertResponseRedirects($location);
        $reply = static::entityManager()->getRepository(Reply::class)->findOneBy(['body' => 'Thanks!']);
        self::assertNotNull($reply);
        self::assertNull($reply->getAuthorId());
        self::assertFalse($reply->isByAgent());
    }

    public function testATicketNeedsAProofAndValidInput(): void
    {
        $this->post(self::API, $this->ticketBody(), ['HTTP_REFERER' => self::API.'/create']);
        self::assertResponseRedirects(self::API.'/create');
        self::assertSame(['verification_code'], array_keys($this->lastErrors()));

        $proof = self::guestProof($this->client, 'gina@example.com', 'ticket', self::API);
        $this->post(self::API, ['verification_code' => '00000000'] + $proof + $this->ticketBody());
        self::assertSame(['verification_code'], array_keys($this->lastErrors()));

        $this->post(self::API, ['guest_name' => '', 'guest_email' => 'nope', 'subject' => '', 'description' => '', 'priority' => 'whenever', 'department_id' => 999]);
        self::assertSame(['guest_name', 'guest_email', 'subject', 'description', 'priority', 'department_id'], array_keys($this->lastErrors()));

        self::assertSame(0, static::entityManager()->getRepository(Ticket::class)->count([]));
    }

    public function testOnlyAGrantOpensTheTicket(): void
    {
        $token = $this->openTicket();
        $ticket = $this->onlyTicket();

        foreach (['forged' => 'AAAA'.substr($token, 4), 'permanent token' => (string) $ticket->getGuestToken(), 'truncated' => substr($token, 0, -3)] as $case => $wrong) {
            $this->client->request('GET', self::API.'/'.$wrong);
            self::assertResponseStatusCodeSame(404, $case);
        }

        $this->post(self::API.'/'.substr($token, 0, -3).'/reply', ['body' => 'sneaky']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, static::entityManager()->getRepository(Reply::class)->count([]));
    }

    public function testLookupIssuesAFreshGrantAndRetiresTheOldOne(): void
    {
        $old = $this->openTicket();
        $ticket = $this->onlyTicket();

        $proof = self::guestProof($this->client, 'GINA@example.com', 'lookup', self::API);
        $this->client->request('POST', self::API.'/lookup', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: (string) json_encode(['email' => 'GINA@example.com', 'reference' => $ticket->getReference()] + $proof));
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        self::assertCount(1, $data);
        self::assertSame(['reference', 'subject', 'guest_access_token', 'expires_at'], array_keys($data[0]));

        $this->visit(self::API.'/'.$data[0]['guest_access_token']);
        $this->client->request('GET', self::API.'/'.$old);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', self::API.'/lookup', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: (string) json_encode(['email' => 'gina@example.com', 'reference' => $ticket->getReference()]));
        self::assertResponseStatusCodeSame(422);
    }

    public function testClosedTicketsTakeNoRepliesAndResolvedOnesTakeARating(): void
    {
        $token = $this->openTicket();
        $ticket = $this->onlyTicket();
        $location = self::API.'/'.$token;

        $tickets = static::getContainer()->get(TicketService::class);
        $tickets->changeStatus($ticket, Ticket::STATUS_RESOLVED);
        $this->post($location.'/rate', ['rating' => 5, 'comment' => 'Lovely']);
        self::assertResponseRedirects();
        $rating = static::entityManager()->getRepository(SatisfactionRating::class)->findOneBy([]);
        self::assertSame(5, $rating?->getRating());
        self::assertSame(['rating' => 5, 'comment' => 'Lovely'], $this->visit($location)['props']['ticket']['satisfaction_rating']);

        $ticket = $tickets->find($ticket->getReference());
        $tickets->changeStatus($ticket, Ticket::STATUS_CLOSED);
        $this->post($location.'/reply', ['body' => 'One more thing']);
        self::assertResponseRedirects();
        self::assertSame(0, static::entityManager()->getRepository(Reply::class)->count(['body' => 'One more thing']));
    }

    public function testThePortalIsClosedWhenGuestTicketsAreOff(): void
    {
        static::getContainer()->get(WidgetSettings::class)->setAllowGuestTickets(false);

        $this->client->request('GET', self::API.'/create');
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', self::API.'/verification', ['email' => 'gina@example.com', 'purpose' => 'ticket']);
        self::assertResponseStatusCodeSame(404);
    }

    private function openTicket(): string
    {
        $proof = self::guestProof($this->client, 'gina@example.com', 'ticket', self::API);
        $this->post(self::API, $this->ticketBody() + $proof);
        self::assertResponseStatusCodeSame(303);

        return substr((string) $this->client->getResponse()->headers->get('Location'), \strlen(self::API) + 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketBody(): array
    {
        return [
            'guest_name' => 'Gina',
            'guest_email' => 'gina@example.com',
            'subject' => 'My order never came',
            'description' => 'Order 1234',
            'priority' => 'high',
            'department_id' => (string) $this->billing->getId(),
            'attachments' => [],
        ];
    }

    private function onlyTicket(): Ticket
    {
        $em = static::entityManager();
        $em->clear();
        $all = $em->getRepository(Ticket::class)->findAll();
        self::assertCount(1, $all);

        return $all[0];
    }

    /**
     * @return array<string, string>
     */
    private function lastErrors(): array
    {
        $errors = $this->client->getRequest()->getSession()->getFlashBag()->peek('errors');

        return end($errors) ?: [];
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

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $server
     */
    private function post(string $uri, array $body, array $server = []): void
    {
        $this->client->request('POST', $uri, server: [
            'REMOTE_ADDR' => sprintf('2001:db8:beef::%x', random_int(1, 0xFFFF)),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'text/html, application/xhtml+xml',
            'HTTP_X_INERTIA' => 'true',
            ...$server,
        ], content: (string) json_encode($body));
    }
}
