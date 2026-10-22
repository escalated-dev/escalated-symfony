<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Ui;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\TicketStatus;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\RouterInterface;

/**
 * Escalated/Admin/Statuses/Index and Form against escalated-laravel's
 * Admin\StatusController prop contract, plus the rules the statuses spec adds:
 * built-ins listed as system rows that cannot be deleted or re-categorised,
 * one default per category, and deleting a custom status detaching tickets.
 */
final class StatusPagesTest extends EscalatedWebTestCase
{
    private const CATEGORIES = ['new', 'open', 'pending', 'on_hold', 'solved'];

    private const STATUS_KEYS = ['id', 'label', 'slug', 'category', 'color', 'description', 'position', 'is_default', 'is_system', 'created_at', 'updated_at'];

    private KernelBrowser $client;
    private TestUser $admin;

    protected function setUp(): void
    {
        $this->client = static::clientWithSchema(['escalated' => ['user_class' => TestUser::class]]);
        $this->admin = new TestUser('admin@example.com', ['ROLE_ESCALATED_ADMIN']);
        $em = static::entityManager();
        $em->persist($this->admin);
        $em->flush();
        $em->persist((new AgentProfile())->setUserId((int) $this->admin->getId()));
        $em->flush();
        $this->client->loginUser($this->admin);
    }

    public function testEveryRouteThePagesCallExists(): void
    {
        $router = static::getContainer()->get(RouterInterface::class);
        \assert($router instanceof RouterInterface);
        $collection = $router->getRouteCollection();

        foreach (['index' => ['GET', '/support/admin/statuses'], 'create' => ['GET', '/support/admin/statuses/create'], 'store' => ['POST', '/support/admin/statuses'], 'edit' => ['GET', '/support/admin/statuses/{id}/edit'], 'update' => ['PUT', '/support/admin/statuses/{id}'], 'destroy' => ['DELETE', '/support/admin/statuses/{id}']] as $action => [$method, $path]) {
            $route = $collection->get('escalated.admin.statuses.'.$action);
            self::assertNotNull($route, $action);
            self::assertSame($path, $route->getPath());
            self::assertContains($method, $route->getMethods());
        }
        self::assertContains('PATCH', $collection->get('escalated.admin.statuses.update')->getMethods());
    }

    public function testIndexListsSystemRowsAndCustomStatusesByCategoryThenPosition(): void
    {
        $this->makeStatus('Awaiting parts', 'pending', position: 2);
        $this->makeStatus('Awaiting vendor', 'pending', position: 1);

        $page = $this->visit('/support/admin/statuses');

        self::assertSame('Escalated/Admin/Statuses/Index', $page['component']);
        self::assertSame(self::CATEGORIES, $page['props']['categories']);
        $statuses = $page['props']['statuses'];
        foreach ($statuses as $row) {
            self::assertSame(self::STATUS_KEYS, array_keys($row));
        }

        $bySlug = array_column($statuses, null, 'slug');
        foreach (['open' => 'open', 'in_progress' => 'open', 'waiting_on_customer' => 'pending', 'waiting_on_agent' => 'open', 'escalated' => 'open', 'resolved' => 'solved', 'closed' => 'solved', 'reopened' => 'open', 'snoozed' => 'on_hold', 'live' => 'open'] as $slug => $category) {
            self::assertArrayHasKey($slug, $bySlug, $slug);
            self::assertTrue($bySlug[$slug]['is_system']);
            self::assertSame($category, $bySlug[$slug]['category'], $slug);
        }
        self::assertSame('Waiting on Customer', $bySlug['waiting_on_customer']['label']);
        self::assertFalse($bySlug['awaiting_parts']['is_system']);

        $order = array_map(static fn (array $s): string => $s['category'].'/'.str_pad((string) $s['position'], 5, '0', \STR_PAD_LEFT), $statuses);
        $sorted = $order;
        sort($sorted);
        self::assertSame($sorted, $order, 'ordered by category then position');
        $pending = array_values(array_filter($statuses, static fn (array $s): bool => !$s['is_system'] && 'pending' === $s['category']));
        self::assertSame(['awaiting_vendor', 'awaiting_parts'], array_column($pending, 'slug'));

        // Listing twice does not seed twice.
        $again = $this->visit('/support/admin/statuses');
        self::assertCount(\count($statuses), $again['props']['statuses']);
    }

    public function testCreateAndEditForms(): void
    {
        $page = $this->visit('/support/admin/statuses/create');
        self::assertSame(['component' => 'Escalated/Admin/Statuses/Form', 'props' => ['categories' => self::CATEGORIES]], $page);

        $status = $this->makeStatus('Awaiting parts', 'pending', description: 'Ordered', color: '#ff0000');
        $page = $this->visit('/support/admin/statuses/'.$status->getId().'/edit');
        self::assertSame('Escalated/Admin/Statuses/Form', $page['component']);
        self::assertSame(self::CATEGORIES, $page['props']['categories']);
        self::assertSame(self::STATUS_KEYS, array_keys($page['props']['status']));
        self::assertSame('Awaiting parts', $page['props']['status']['label']);
        self::assertSame('awaiting_parts', $page['props']['status']['slug']);
        self::assertSame('pending', $page['props']['status']['category']);
        self::assertSame('#ff0000', $page['props']['status']['color']);
        self::assertSame('Ordered', $page['props']['status']['description']);
        self::assertFalse($page['props']['status']['is_system']);

        $this->client->request('GET', '/support/admin/statuses/999999/edit');
        self::assertResponseStatusCodeSame(404);
    }

    public function testStoreGeneratesAUniqueSlugAndRedirectsWithAFlash(): void
    {
        $this->send('POST', '/support/admin/statuses', ['label' => 'Awaiting Parts!', 'category' => 'pending', 'color' => '#123456', 'description' => 'Ordered', 'position' => 3, 'is_default' => false]);
        self::assertResponseRedirects('/support/admin/statuses', 303);
        self::assertContains('Status created.', $this->client->getRequest()->getSession()->getFlashBag()->peek('success'));

        $this->send('POST', '/support/admin/statuses', ['label' => 'Awaiting parts', 'category' => 'on_hold', 'color' => '#654321']);
        $this->send('POST', '/support/admin/statuses', ['label' => 'Awaiting  parts', 'category' => 'on_hold', 'color' => '#654321', 'description' => '']);

        $rows = static::entityManager()->getRepository(TicketStatus::class)->findBy(['label' => ['Awaiting Parts!', 'Awaiting parts', 'Awaiting  parts']], ['id' => 'ASC']);
        self::assertSame(['awaiting_parts', 'awaiting_parts_2', 'awaiting_parts_3'], array_map(static fn (TicketStatus $s): string => $s->getSlug(), $rows));
        self::assertSame(3, $rows[0]->getPosition());
        self::assertSame('Ordered', $rows[0]->getDescription());
        self::assertSame(0, $rows[1]->getPosition());
        self::assertNull($rows[2]->getDescription());
        self::assertFalse($rows[0]->isSystem());

        // A label that slugs to a built-in still gets its own slug.
        $this->send('POST', '/support/admin/statuses', ['label' => 'Open', 'category' => 'open', 'color' => '#000000']);
        $custom = static::entityManager()->getRepository(TicketStatus::class)->findOneBy(['label' => 'Open', 'isSystem' => false]);
        self::assertSame('open_2', $custom?->getSlug());
    }

    public function testValidationMatchesLaravel(): void
    {
        $this->send('POST', '/support/admin/statuses', ['label' => '', 'category' => 'later', 'color' => str_repeat('a', 21), 'position' => 'x', 'is_default' => 'maybe', 'description' => ['no']]);
        self::assertSame(['label', 'category', 'color', 'description', 'position', 'is_default'], array_keys($this->lastErrors()));

        $this->send('POST', '/support/admin/statuses', ['label' => str_repeat('a', 256), 'color' => '#fff']);
        self::assertSame(['label', 'category'], array_keys($this->lastErrors()));
        self::assertSame(0, static::entityManager()->getRepository(TicketStatus::class)->count(['isSystem' => false]));

        $status = $this->makeStatus('Awaiting parts', 'pending');
        $this->send('PUT', '/support/admin/statuses/'.$status->getId(), ['label' => 'Renamed', 'category' => 'pending']);
        self::assertSame(['color'], array_keys($this->lastErrors()));
    }

    public function testJsonClientsGetA422(): void
    {
        $this->client->request('POST', '/support/admin/statuses', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: (string) json_encode(['label' => '']));
        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('label', $body['errors']);
    }

    public function testUpdateKeepsTheSlugAndIsDefaultIsExclusivePerCategory(): void
    {
        $a = $this->makeStatus('Awaiting parts', 'pending', isDefault: true);
        $other = $this->makeStatus('On ice', 'on_hold', isDefault: true);

        $this->send('POST', '/support/admin/statuses', ['label' => 'Awaiting vendor', 'category' => 'pending', 'color' => '#111111', 'is_default' => true]);
        $em = static::entityManager();
        $em->clear();
        self::assertFalse($em->getRepository(TicketStatus::class)->find($a->getId())->isDefault());
        self::assertTrue($em->getRepository(TicketStatus::class)->find($other->getId())->isDefault());
        $b = $em->getRepository(TicketStatus::class)->findOneBy(['slug' => 'awaiting_vendor']);
        self::assertTrue($b?->isDefault());

        $this->send('PUT', '/support/admin/statuses/'.$a->getId(), ['label' => 'Parts on order', 'category' => 'pending', 'color' => '#222222', 'description' => 'x', 'position' => 4, 'is_default' => true]);
        self::assertResponseRedirects('/support/admin/statuses', 303);
        $em->clear();
        $a = $em->getRepository(TicketStatus::class)->find($a->getId());
        self::assertSame('Parts on order', $a->getLabel());
        self::assertSame('awaiting_parts', $a->getSlug());
        self::assertSame('#222222', $a->getColor());
        self::assertSame(4, $a->getPosition());
        self::assertTrue($a->isDefault());
        self::assertFalse($em->getRepository(TicketStatus::class)->find($b->getId())->isDefault());

        $this->send('PATCH', '/support/admin/statuses/'.$a->getId(), ['label' => 'Parts on order', 'category' => 'on_hold', 'color' => '#222222']);
        $em->clear();
        self::assertSame('on_hold', $em->getRepository(TicketStatus::class)->find($a->getId())->getCategory());
    }

    public function testSystemRowsKeepSlugAndCategoryAndCannotBeDeleted(): void
    {
        $this->visit('/support/admin/statuses');
        $em = static::entityManager();
        $resolved = $em->getRepository(TicketStatus::class)->findOneBy(['slug' => 'resolved']);
        self::assertNotNull($resolved);

        $this->send('PUT', '/support/admin/statuses/'.$resolved->getId(), ['label' => 'Done', 'category' => 'open', 'color' => '#00ff00', 'is_default' => true]);
        self::assertSame(['category'], array_keys($this->lastErrors()));
        $em->clear();
        self::assertSame('Resolved', $em->getRepository(TicketStatus::class)->find($resolved->getId())->getLabel());

        $this->send('PUT', '/support/admin/statuses/'.$resolved->getId(), ['label' => 'Done', 'category' => 'solved', 'color' => '#00ff00', 'description' => 'All set', 'position' => 9, 'is_default' => true]);
        self::assertResponseRedirects('/support/admin/statuses', 303);
        $em->clear();
        $resolved = $em->getRepository(TicketStatus::class)->find($resolved->getId());
        self::assertSame(['Done', 'resolved', 'solved', '#00ff00', 'All set', 9, true, true], [$resolved->getLabel(), $resolved->getSlug(), $resolved->getCategory(), $resolved->getColor(), $resolved->getDescription(), $resolved->getPosition(), $resolved->isDefault(), $resolved->isSystem()]);

        $this->send('DELETE', '/support/admin/statuses/'.$resolved->getId(), []);
        self::assertResponseRedirects('/support/admin/statuses', 303);
        self::assertNotEmpty($this->client->getRequest()->getSession()->getFlashBag()->peek('error'));
        $em->clear();
        self::assertNotNull($em->getRepository(TicketStatus::class)->find($resolved->getId()));

        $this->client->request('DELETE', '/support/admin/statuses/'.$resolved->getId(), server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testDestroyDetachesTicketsAndLeavesTheirStatusValid(): void
    {
        $status = $this->makeStatus('Awaiting parts', 'pending');
        $tickets = static::getContainer()->get(TicketService::class);
        \assert($tickets instanceof TicketService);
        $ticket = $tickets->create(['subject' => 'Parts']);
        $tickets->changeStatus($ticket, 'awaiting_parts');

        $id = $status->getId();
        $this->send('DELETE', '/support/admin/statuses/'.$id, []);
        self::assertResponseRedirects('/support/admin/statuses', 303);
        self::assertContains('Status deleted.', $this->client->getRequest()->getSession()->getFlashBag()->peek('success'));

        $em = static::entityManager();
        $em->clear();
        self::assertNull($em->getRepository(TicketStatus::class)->find($id));
        $ticket = $em->getRepository(Ticket::class)->find($ticket->getId());
        self::assertNull($ticket->getCustomStatus());
        self::assertSame(Ticket::STATUS_WAITING_ON_CUSTOMER, $ticket->getStatus());
    }

    public function testNonAdminsAreForbidden(): void
    {
        $agent = new TestUser('agent@example.com');
        $em = static::entityManager();
        $em->persist($agent);
        $em->flush();
        $em->persist((new AgentProfile())->setUserId((int) $agent->getId()));
        $em->flush();
        $status = $this->makeStatus('Awaiting parts', 'pending');
        $this->client->loginUser($agent);

        foreach ([['GET', '/support/admin/statuses'], ['GET', '/support/admin/statuses/create'], ['POST', '/support/admin/statuses'], ['GET', '/support/admin/statuses/'.$status->getId().'/edit'], ['PUT', '/support/admin/statuses/'.$status->getId()], ['DELETE', '/support/admin/statuses/'.$status->getId()]] as [$method, $uri]) {
            $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: '{"label":"x","category":"open","color":"#000"}');
            self::assertResponseStatusCodeSame(403, $method.' '.$uri);
        }
        $em->clear();
        self::assertNotNull($em->getRepository(TicketStatus::class)->find($status->getId()));
    }

    private function makeStatus(string $label, string $category, int $position = 0, bool $isDefault = false, ?string $description = null, string $color = '#6b7280'): TicketStatus
    {
        $status = (new TicketStatus())
            ->setLabel($label)
            ->setSlug(strtolower(str_replace(' ', '_', $label)))
            ->setCategory($category)
            ->setColor($color)
            ->setDescription($description)
            ->setPosition($position)
            ->setIsDefault($isDefault);
        $em = static::entityManager();
        $em->persist($status);
        $em->flush();

        return $status;
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
     * @param array<string, mixed> $body
     */
    private function send(string $method, string $uri, array $body): void
    {
        $this->client->request($method, $uri, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'text/html, application/xhtml+xml',
            'HTTP_X_INERTIA' => 'true',
        ], content: (string) json_encode($body));
    }
}
