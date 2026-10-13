<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Access;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use PHPUnit\Framework\Attributes\DataProvider;

final class TicketVisibilityTest extends EscalatedWebTestCase
{
    public static function paths(): iterable
    {
        yield 'list' => ['GET', '/support/api/v1/tickets', true];
        yield 'search' => ['GET', '/support/api/v1/tickets?search=Secret', true];
        yield 'detail' => ['GET', '/support/api/v1/tickets/{ref}', false];
        yield 'update' => ['PATCH', '/support/api/v1/tickets/{ref}', false];
        yield 'close' => ['POST', '/support/api/v1/tickets/{ref}/status', false];
        yield 'reply' => ['POST', '/support/agent/tickets/{ref}/reply', false];
        yield 'assign' => ['POST', '/support/agent/tickets/{ref}/assign', false];
        yield 'agent detail' => ['GET', '/support/agent/tickets/{ref}', false];
        yield 'widget' => ['GET', '/support/widget/api/tickets/{ref}', false];
    }

    #[DataProvider('paths')]
    public function testOtherDepartmentsAreHidden(string $method, string $path, bool $list): void
    {
        $client = static::clientWithSchema(['escalated' => ['visibility' => 'department']]);
        static::getContainer()->get(\Escalated\Symfony\Widget\WidgetSettings::class)->setEnabled(true);
        $em = static::entityManager();
        $user = new TestUser('agent@example.com');
        $em->persist($user);
        $em->flush();
        $profile = new AgentProfile();
        $profile->setUserId($user->getId());
        $em->persist($profile);
        $department = (new Department())->setName('Private')->setSlug('private');
        $em->persist($department);
        $ticket = (new Ticket())->setSubject('Secret')->setDepartment($department)->setGuestToken('guest-secret');
        $em->persist($ticket);
        $em->flush();
        $client->loginUser($user);
        $client->jsonRequest($method, str_replace(['{ref}', '{token}'], [$ticket->getReference(), 'guest-secret'], $path), ['subject' => 'Changed', 'status' => 'closed'], ['HTTP_X_GUEST_TOKEN' => 'guest-secret']);
        if ($list) {
            self::assertResponseIsSuccessful();
            self::assertSame([], json_decode($client->getResponse()->getContent(), true)['data']);
        } else {
            self::assertResponseStatusCodeSame(404);
        }
    }
}
