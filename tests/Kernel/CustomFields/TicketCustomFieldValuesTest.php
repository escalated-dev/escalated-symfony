<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\CustomFields;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\CustomField;
use Escalated\Symfony\Entity\CustomFieldValue;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Ticket custom field values through the `custom_fields` key: written by the
 * customer create form, the admin ticket update and the JSON API, validated
 * per type before the ticket is touched, and read back on the create and
 * show pages and the API detail.
 */
final class TicketCustomFieldValuesTest extends EscalatedWebTestCase
{
    private KernelBrowser $client;
    private TestUser $admin;
    private TestUser $customer;

    protected function setUp(): void
    {
        $this->client = static::clientWithSchema(['escalated' => ['user_class' => TestUser::class]]);
        $em = static::entityManager();
        $this->admin = new TestUser('admin@example.com', ['ROLE_ESCALATED_ADMIN']);
        $this->customer = new TestUser('customer@example.com');
        $em->persist($this->admin);
        $em->persist($this->customer);
        $em->flush();
        $em->persist((new AgentProfile())->setUserId((int) $this->admin->getId()));

        $this->field('Order number', 'order_number', 'text', required: true, position: 1);
        $this->field('Seats', 'seats', 'number', position: 2);
        $this->field('Renewal', 'renewal', 'date', position: 3);
        $this->field('Plan', 'plan', 'select', ['free', 'pro'], position: 4);
        $this->field('Modules', 'modules', 'multi_select', ['chat', 'kb', 'sla'], position: 5);
        $this->field('Urgent', 'urgent', 'checkbox', position: 6);
        $this->field('Notes', 'notes', 'textarea', position: 7);
        $this->field('Retired', 'retired', 'text', active: false, position: 8);
        $this->field('Company size', 'company_size', 'number', context: 'organization', position: 0);
        $em->flush();
    }

    public function testCreatePageListsActiveTicketFieldsInOrder(): void
    {
        $this->client->loginUser($this->customer);
        $page = $this->visit('/support/customer/tickets/create');

        self::assertSame('Escalated/Customer/Create', $page['component']);
        $fields = $page['props']['custom_fields'];
        self::assertSame(['order_number', 'seats', 'renewal', 'plan', 'modules', 'urgent', 'notes'], array_column($fields, 'slug'));
        self::assertSame(['id', 'name', 'slug', 'type', 'options', 'required', 'placeholder', 'description', 'position'], array_keys($fields[0]));
        self::assertTrue($fields[0]['required']);
        self::assertSame(['free', 'pro'], $fields[3]['options']);
    }

    public function testCustomerCreateStoresEncodedValuesAndShowDecodesThem(): void
    {
        $this->client->loginUser($this->customer);
        $this->send('POST', '/support/customer/tickets', $this->ticket([
            'order_number' => 'ORD-1', 'seats' => '12', 'renewal' => '2026-12-01', 'plan' => 'pro',
            'modules' => ['chat', 'sla'], 'urgent' => true, 'notes' => '',
        ]));
        $ticket = $this->ticketBySubject();
        self::assertNotNull($ticket, (string) json_encode($this->lastErrors()));
        self::assertResponseRedirects('/support/customer/tickets/'.$ticket->getReference());

        self::assertSame([
            'modules' => '["chat","sla"]', 'order_number' => 'ORD-1', 'plan' => 'pro', 'renewal' => '2026-12-01', 'seats' => '12', 'urgent' => '1',
        ], $this->storedValues($ticket));

        $page = $this->visit('/support/customer/tickets/'.$ticket->getReference());
        $values = array_column($page['props']['custom_fields'], 'value', 'slug');
        self::assertSame(['order_number' => 'ORD-1', 'seats' => 12, 'renewal' => '2026-12-01', 'plan' => 'pro', 'modules' => ['chat', 'sla'], 'urgent' => true, 'notes' => null], $values);
        self::assertSame(['id', 'name', 'slug', 'type', 'options', 'required', 'placeholder', 'description', 'position', 'value'], array_keys($page['props']['custom_fields'][0]));
    }

    public function testCreateRejectsBadValuesAndCreatesNoTicket(): void
    {
        $this->client->loginUser($this->customer);

        $this->send('POST', '/support/customer/tickets', $this->ticket(['seats' => '3']));
        self::assertSame(['custom_fields.order_number'], array_keys($this->lastErrors()));

        $this->send('POST', '/support/customer/tickets', $this->ticket([
            'order_number' => '  ', 'seats' => 'many', 'renewal' => '2026-02-30', 'plan' => 'enterprise',
            'modules' => ['chat', 'voice'], 'urgent' => 'maybe', 'notes' => ['not', 'text'],
        ]));
        self::assertSame([
            'custom_fields.order_number', 'custom_fields.seats', 'custom_fields.renewal', 'custom_fields.plan',
            'custom_fields.modules', 'custom_fields.urgent', 'custom_fields.notes',
        ], array_keys($this->lastErrors()));

        $this->send('POST', '/support/customer/tickets', $this->ticket(['order_number' => str_repeat('x', 256), 'modules' => 'chat']));
        self::assertSame(['custom_fields.order_number', 'custom_fields.modules'], array_keys($this->lastErrors()));

        $this->send('POST', '/support/customer/tickets', $this->ticket(['order_number' => 'A', 'retired' => 'x', 'company_size' => '5', 'nope' => 'x']));
        self::assertSame(['custom_fields.retired', 'custom_fields.company_size', 'custom_fields.nope'], array_keys($this->lastErrors()));

        $this->send('POST', '/support/customer/tickets', ['subject' => 'Has fields', 'description' => 'Body', 'custom_fields' => 'flat']);
        self::assertSame(['custom_fields'], array_keys($this->lastErrors()));

        self::assertNull($this->ticketBySubject());
        self::assertSame(0, static::entityManager()->getRepository(CustomFieldValue::class)->count([]));
    }

    public function testCheckboxAcceptsTheUsualSpellings(): void
    {
        $this->client->loginUser($this->customer);
        foreach ([[0, '0'], ['1', '1'], ['true', '1'], ['false', '0'], [false, '0']] as $i => [$given, $stored]) {
            $this->send('POST', '/support/customer/tickets', $this->ticket(['order_number' => 'O'.$i, 'urgent' => $given], 'Box '.$i));
            $ticket = $this->ticketBySubject('Box '.$i);
            self::assertNotNull($ticket, json_encode($given).' '.json_encode($this->lastErrors()));
            self::assertSame($stored, $this->storedValues($ticket)['urgent']);
        }
    }

    public function testAdminUpdateChangesOnlyTheFieldsSentAndClearsBlanks(): void
    {
        $ticket = $this->createTicketWithValues();
        $this->client->loginUser($this->admin);
        $url = '/support/admin/tickets/'.$ticket->getReference();

        $this->send('PATCH', $url, ['subject' => 'Renamed']);
        self::assertResponseRedirects();
        self::assertSame('ORD-9', $this->storedValues($ticket)['order_number'], 'omitting custom_fields changes nothing');

        $this->send('PATCH', $url, ['custom_fields' => ['seats' => 40, 'plan' => '']]);
        self::assertResponseRedirects();
        $values = $this->storedValues($ticket);
        self::assertSame('40', $values['seats']);
        self::assertArrayNotHasKey('plan', $values, 'a blank optional value is removed');
        self::assertSame('ORD-9', $values['order_number']);

        $this->send('PATCH', $url, ['subject' => 'Again', 'custom_fields' => ['order_number' => '', 'seats' => 'x']]);
        self::assertSame(['custom_fields.order_number', 'custom_fields.seats'], array_keys($this->lastErrors()));
        static::entityManager()->clear();
        self::assertSame('Renamed', static::entityManager()->getRepository(Ticket::class)->find($ticket->getId())->getSubject(), 'a rejected update writes nothing');
        self::assertSame('40', $this->storedValues($ticket)['seats']);

        foreach (['/support/admin/tickets/', '/support/agent/tickets/'] as $desk) {
            $page = $this->visit($desk.$ticket->getReference());
            $values = array_column($page['props']['custom_fields'], 'value', 'slug');
            self::assertSame(40, $values['seats']);
            self::assertNull($values['plan']);
            self::assertSame(['chat'], $values['modules']);
        }
    }

    public function testApiCreateUpdateAndShow(): void
    {
        $this->client->loginUser($this->admin);

        $this->client->jsonRequest('POST', '/support/api/v1/tickets', ['subject' => 'Via API', 'description' => 'Body', 'custom_fields' => ['seats' => 'x']]);
        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['custom_fields.order_number', 'custom_fields.seats'], array_keys($body['errors']));
        self::assertNull($this->ticketBySubject('Via API'));

        $this->client->jsonRequest('POST', '/support/api/v1/tickets', ['subject' => 'Via API', 'description' => 'Body', 'custom_fields' => ['order_number' => 'API-1', 'urgent' => 1]]);
        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        $values = array_column($data['custom_fields'], 'value', 'slug');
        self::assertSame('API-1', $values['order_number']);
        self::assertTrue($values['urgent']);

        $ticket = $this->ticketBySubject('Via API');
        self::assertNotNull($ticket);
        $this->client->jsonRequest('PATCH', '/support/api/v1/tickets/'.$ticket->getReference(), ['custom_fields' => ['plan' => 'gold']]);
        self::assertResponseStatusCodeSame(422);
        $this->client->jsonRequest('PATCH', '/support/api/v1/tickets/'.$ticket->getReference(), ['custom_fields' => ['plan' => 'free', 'urgent' => false]]);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('GET', '/support/api/v1/tickets/'.$ticket->getReference());
        $values = array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['data']['custom_fields'], 'value', 'slug');
        self::assertSame('free', $values['plan']);
        self::assertFalse($values['urgent']);
        self::assertSame('API-1', $values['order_number']);
    }

    public function testWithNoFieldsDefinedTicketsBehaveAsBefore(): void
    {
        $em = static::entityManager();
        foreach ($em->getRepository(CustomField::class)->findAll() as $field) {
            $em->remove($field);
        }
        $em->flush();

        $this->client->loginUser($this->customer);
        $this->send('POST', '/support/customer/tickets', ['subject' => 'Has fields', 'description' => 'Body']);
        self::assertNotNull($this->ticketBySubject());
        self::assertSame([], $this->visit('/support/customer/tickets/create')['props']['custom_fields']);
    }

    private function createTicketWithValues(): Ticket
    {
        $this->client->loginUser($this->customer);
        $this->send('POST', '/support/customer/tickets', $this->ticket(['order_number' => 'ORD-9', 'seats' => 3, 'plan' => 'pro', 'modules' => ['chat']]));
        $ticket = $this->ticketBySubject();
        self::assertNotNull($ticket);

        return $ticket;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function ticket(array $values, string $subject = 'Has fields'): array
    {
        return ['subject' => $subject, 'description' => 'Body', 'custom_fields' => $values];
    }

    private function ticketBySubject(string $subject = 'Has fields'): ?Ticket
    {
        static::entityManager()->clear();

        return static::entityManager()->getRepository(Ticket::class)->findOneBy(['subject' => $subject]);
    }

    /**
     * @return array<string, string|null>
     */
    private function storedValues(Ticket $ticket): array
    {
        $em = static::entityManager();
        $em->clear();
        $out = [];
        foreach ($em->getRepository(CustomFieldValue::class)->findBy(['entityType' => 'ticket', 'entityId' => $ticket->getId()]) as $value) {
            $out[$value->getCustomField()->getSlug()] = $value->getValue();
        }
        ksort($out);

        return $out;
    }

    /**
     * @param list<string>|null $options
     */
    private function field(string $name, string $slug, string $type, ?array $options = null, bool $required = false, bool $active = true, string $context = 'ticket', int $position = 0): void
    {
        static::entityManager()->persist((new CustomField())->setName($name)->setSlug($slug)->setFieldType($type)
            ->setOptions($options)->setIsRequired($required)->setIsActive($active)->setEntityType($context)->setPosition($position));
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
