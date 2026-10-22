<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Ui;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\CustomField;
use Escalated\Symfony\Entity\CustomFieldValue;
use Escalated\Symfony\Entity\CustomObject;
use Escalated\Symfony\Entity\CustomObjectRecord;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\RouterInterface;

/**
 * Escalated/Admin/CustomFields/{Index,Form} and
 * Escalated/Admin/CustomObjects/{Index,Form,Records}, against the props the
 * shared pages read and the routes they call (escalated-laravel's
 * Admin\CustomFieldController and Admin\CustomObjectController).
 */
final class CustomFieldPagesTest extends EscalatedWebTestCase
{
    private const PAGE_ROUTES = [
        'escalated.admin.custom-fields.index', 'escalated.admin.custom-fields.create', 'escalated.admin.custom-fields.store',
        'escalated.admin.custom-fields.edit', 'escalated.admin.custom-fields.update', 'escalated.admin.custom-fields.destroy',
        'escalated.admin.custom-fields.reorder',
        'escalated.admin.custom-objects.index', 'escalated.admin.custom-objects.create', 'escalated.admin.custom-objects.store',
        'escalated.admin.custom-objects.edit', 'escalated.admin.custom-objects.update', 'escalated.admin.custom-objects.destroy',
        'escalated.admin.custom-objects.records', 'escalated.admin.custom-objects.records.store',
        'escalated.admin.custom-objects.records.update', 'escalated.admin.custom-objects.records.destroy',
    ];

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
        $missing = array_values(array_filter(self::PAGE_ROUTES, static fn (string $name): bool => null === $router->getRouteCollection()->get($name)));

        self::assertSame([], $missing);
    }

    public function testNonAdminsCannotReachTheAdmin(): void
    {
        $em = static::entityManager();
        $agent = new TestUser('agent@example.com');
        $em->persist($agent);
        $em->flush();
        $this->client->loginUser($agent);

        $this->client->request('GET', '/support/admin/custom-fields');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/support/admin/custom-objects');
        self::assertResponseStatusCodeSame(403);
    }

    public function testCustomFieldIndexAndForm(): void
    {
        $page = $this->visit('/support/admin/custom-fields/create');
        self::assertSame(['component' => 'Escalated/Admin/CustomFields/Form', 'props' => ['contexts' => ['ticket', 'user', 'organization']]], $page);

        $this->send('POST', '/support/admin/custom-fields', [
            'name' => 'Order Number', 'type' => 'text', 'context' => 'ticket', 'options' => [], 'required' => true,
            'placeholder' => 'ORD-123', 'description' => 'From the receipt', 'position' => 2, 'active' => true,
        ]);
        self::assertResponseRedirects('/support/admin/custom-fields');
        self::assertSame(['Custom field created.'], $this->client->getRequest()->getSession()->getFlashBag()->peek('success'));

        $em = static::entityManager();
        $field = $em->getRepository(CustomField::class)->findOneBy(['name' => 'Order Number']);
        self::assertNotNull($field);
        self::assertSame('order_number', $field->getSlug());
        self::assertTrue($field->isRequired());
        self::assertSame('ORD-123', $field->getPlaceholder());

        // Same name again: the slug is de-duplicated.
        $this->send('POST', '/support/admin/custom-fields', ['name' => 'Order Number', 'type' => 'select', 'context' => 'user', 'options' => ['a', 'b'], 'position' => 1]);
        $second = $em->getRepository(CustomField::class)->findOneBy(['slug' => 'order_number_2']);
        self::assertNotNull($second);
        self::assertSame(['a', 'b'], $second->getOptions());
        self::assertSame('user', $second->getEntityType());

        $page = $this->visit('/support/admin/custom-fields');
        self::assertSame('Escalated/Admin/CustomFields/Index', $page['component']);
        self::assertSame(['order_number_2', 'order_number'], array_column($page['props']['fields'], 'slug'));
        $row = $page['props']['fields'][1];
        foreach (['id', 'name', 'slug', 'type', 'context', 'options', 'required', 'placeholder', 'description', 'validation_rules', 'conditions', 'position', 'active', 'created_at', 'updated_at'] as $key) {
            self::assertArrayHasKey($key, $row);
        }
        self::assertSame('text', $row['type']);
        self::assertSame('ticket', $row['context']);
        self::assertTrue($row['required']);
        self::assertTrue($row['active']);
        self::assertSame(2, $row['position']);

        $page = $this->visit('/support/admin/custom-fields/'.$field->getId().'/edit');
        self::assertSame('Escalated/Admin/CustomFields/Form', $page['component']);
        self::assertSame(['ticket', 'user', 'organization'], $page['props']['contexts']);
        self::assertSame('Order Number', $page['props']['field']['name']);
        self::assertSame('From the receipt', $page['props']['field']['description']);

        $this->send('PUT', '/support/admin/custom-fields/'.$field->getId(), [
            'name' => 'Order #', 'type' => 'number', 'context' => 'ticket', 'options' => [], 'required' => false,
            'placeholder' => '', 'description' => '', 'position' => 5, 'active' => false,
        ]);
        self::assertResponseRedirects('/support/admin/custom-fields');
        $em->clear();
        $field = $em->getRepository(CustomField::class)->find($field->getId());
        self::assertSame('Order #', $field->getName());
        self::assertSame('order_number', $field->getSlug(), 'renaming keeps the slug values are keyed by');
        self::assertSame('number', $field->getFieldType());
        self::assertFalse($field->isActive());
        self::assertFalse($field->isRequired());
        self::assertNull($field->getPlaceholder());

        $this->send('POST', '/support/admin/custom-fields/reorder', ['positions' => [['id' => $field->getId(), 'position' => 0], ['id' => $second->getId(), 'position' => 9]]]);
        self::assertResponseRedirects();
        $em->clear();
        self::assertSame(0, $em->getRepository(CustomField::class)->find($field->getId())->getPosition());
        self::assertSame(9, $em->getRepository(CustomField::class)->find($second->getId())->getPosition());

        $this->send('POST', '/support/admin/custom-fields', ['name' => '', 'type' => 'colour', 'context' => 'planet']);
        self::assertSame(['name', 'type', 'context'], array_keys($this->lastErrors()));
        $this->send('POST', '/support/admin/custom-fields', ['name' => 'Pick', 'type' => 'select', 'context' => 'ticket', 'options' => []]);
        self::assertSame(['options'], array_keys($this->lastErrors()));
        $this->send('POST', '/support/admin/custom-fields/reorder', ['positions' => [['id' => 99999, 'position' => 1]]]);
        self::assertArrayHasKey('positions', $this->lastErrors());

        $field = $em->getRepository(CustomField::class)->find($field->getId());
        $value = (new CustomFieldValue())->setCustomField($field)->setEntityType('ticket')->setEntityId(1)->setValue('5');
        $em->persist($value);
        $em->flush();
        $valueId = $value->getId();

        $this->send('DELETE', '/support/admin/custom-fields/'.$field->getId(), []);
        self::assertResponseRedirects('/support/admin/custom-fields');
        $em->clear();
        self::assertNull($em->getRepository(CustomField::class)->find($field->getId()));
        self::assertNull($em->getRepository(CustomFieldValue::class)->find($valueId), 'deleting a field deletes its values');
    }

    public function testCustomObjectIndexAndForm(): void
    {
        $page = $this->visit('/support/admin/custom-objects/create');
        self::assertSame('Escalated/Admin/CustomObjects/Form', $page['component']);
        self::assertNull($page['props']['object'] ?? null);

        $schema = [
            ['name' => 'plan', 'type' => 'select', 'required' => true, 'options' => ['free', 'pro']],
            ['name' => 'seats', 'type' => 'number'],
        ];
        $this->send('POST', '/support/admin/custom-objects', ['name' => 'Subscriptions', 'slug' => 'subscriptions', 'fields_schema' => $schema]);
        self::assertResponseRedirects('/support/admin/custom-objects');
        $em = static::entityManager();
        $object = $em->getRepository(CustomObject::class)->findOneBy(['slug' => 'subscriptions']);
        self::assertNotNull($object);
        self::assertEquals($schema, $object->getFieldDefinitions());

        $em->persist((new CustomObjectRecord())->setCustomObject($object)->setData(['plan' => 'pro']));
        $em->flush();

        $page = $this->visit('/support/admin/custom-objects');
        self::assertSame('Escalated/Admin/CustomObjects/Index', $page['component']);
        $row = $page['props']['objects'][0];
        foreach (['id', 'name', 'slug', 'fields_schema', 'records_count', 'created_at', 'updated_at'] as $key) {
            self::assertArrayHasKey($key, $row);
        }
        self::assertSame(1, $row['records_count']);
        self::assertEquals($schema, $row['fields_schema']);

        $page = $this->visit('/support/admin/custom-objects/'.$object->getId().'/edit');
        self::assertSame('Escalated/Admin/CustomObjects/Form', $page['component']);
        self::assertSame('subscriptions', $page['props']['object']['slug']);
        self::assertEquals($schema, $page['props']['object']['fields_schema']);

        $this->send('PUT', '/support/admin/custom-objects/'.$object->getId(), ['name' => 'Plans', 'slug' => 'subscriptions', 'fields_schema' => [['name' => 'plan', 'type' => 'text']]]);
        self::assertResponseRedirects('/support/admin/custom-objects');
        $em->clear();
        self::assertSame('Plans', $em->getRepository(CustomObject::class)->find($object->getId())->getName());

        $this->send('POST', '/support/admin/custom-objects', ['name' => 'Dup', 'slug' => 'subscriptions', 'fields_schema' => [['name' => 'x', 'type' => 'text']]]);
        self::assertSame(['slug'], array_keys($this->lastErrors()));
        $this->send('POST', '/support/admin/custom-objects', ['name' => '', 'slug' => 'bad slug!', 'fields_schema' => []]);
        self::assertSame(['name', 'slug', 'fields_schema'], array_keys($this->lastErrors()));
        $this->send('POST', '/support/admin/custom-objects', ['name' => 'X', 'slug' => 'x', 'fields_schema' => [['name' => '', 'type' => 'colour']]]);
        self::assertSame(['fields_schema.0.name', 'fields_schema.0.type'], array_keys($this->lastErrors()));

        $records = $em->getRepository(CustomObjectRecord::class)->findAll();
        self::assertCount(1, $records);
        $recordId = $records[0]->getId();
        $this->send('DELETE', '/support/admin/custom-objects/'.$object->getId(), []);
        self::assertResponseRedirects('/support/admin/custom-objects');
        $em->clear();
        self::assertNull($em->getRepository(CustomObject::class)->find($object->getId()));
        self::assertNull($em->getRepository(CustomObjectRecord::class)->find($recordId), 'deleting an object deletes its records');
    }

    public function testRecordsPageAndWrites(): void
    {
        $em = static::entityManager();
        $object = (new CustomObject())->setName('Assets')->setSlug('assets')->setFieldDefinitions([
            ['name' => 'serial', 'type' => 'text', 'required' => true],
            ['name' => 'cost', 'type' => 'number'],
            ['name' => 'bought', 'type' => 'date'],
            ['name' => 'tier', 'type' => 'select', 'options' => ['gold', 'silver']],
        ]);
        $other = (new CustomObject())->setName('Other')->setSlug('other')->setFieldDefinitions([['name' => 'x', 'type' => 'text']]);
        $em->persist($object);
        $em->persist($other);
        $em->flush();
        $foreign = (new CustomObjectRecord())->setCustomObject($other)->setData(['x' => 'y']);
        $em->persist($foreign);
        $em->flush();

        $base = '/support/admin/custom-objects/'.$object->getId().'/records';
        $this->send('POST', $base, ['data' => ['serial' => 'A1', 'cost' => '12.5', 'bought' => '2026-01-31', 'tier' => 'gold']]);
        self::assertResponseRedirects();
        $this->send('POST', $base, ['data' => ['serial' => 'B2']]);
        self::assertContains('Record created.', $this->client->getRequest()->getSession()->getFlashBag()->peek('success'));

        $page = $this->visit($base);
        self::assertSame('Escalated/Admin/CustomObjects/Records', $page['component']);
        foreach (['id', 'name', 'slug', 'fields_schema'] as $key) {
            self::assertArrayHasKey($key, $page['props']['object']);
        }
        self::assertSame(['B2', 'A1'], array_map(static fn (array $r): string => $r['data']['serial'], $page['props']['records']));
        foreach (['id', 'object_id', 'data', 'created_at', 'updated_at'] as $key) {
            self::assertArrayHasKey($key, $page['props']['records'][0]);
        }
        self::assertSame($object->getId(), $page['props']['records'][0]['object_id']);
        $recordId = $page['props']['records'][0]['id'];

        $this->send('POST', $base, ['data' => ['serial' => '', 'cost' => 'lots', 'bought' => '2026-02-30', 'tier' => 'bronze', 'colour' => 'red']]);
        self::assertSame(['data.serial', 'data.cost', 'data.bought', 'data.tier', 'data.colour'], array_keys($this->lastErrors()));
        $this->send('POST', $base, ['data' => 'nope']);
        self::assertSame(['data'], array_keys($this->lastErrors()));
        self::assertCount(2, $em->getRepository(CustomObjectRecord::class)->findBy(['customObject' => $object]));

        $this->send('PUT', $base.'/'.$recordId, ['data' => ['serial' => 'B3']]);
        self::assertResponseRedirects();
        $em->clear();
        self::assertSame(['serial' => 'B3'], $em->getRepository(CustomObjectRecord::class)->find($recordId)->getData());

        $this->send('PUT', $base.'/'.$foreign->getId(), ['data' => ['serial' => 'stolen']]);
        self::assertResponseStatusCodeSame(404);
        $this->send('DELETE', $base.'/'.$foreign->getId(), []);
        self::assertResponseStatusCodeSame(404);

        $this->send('DELETE', $base.'/'.$recordId, []);
        self::assertResponseRedirects();
        $em->clear();
        self::assertNull($em->getRepository(CustomObjectRecord::class)->find($recordId));
        self::assertNotNull($em->getRepository(CustomObjectRecord::class)->find($foreign->getId()));
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
