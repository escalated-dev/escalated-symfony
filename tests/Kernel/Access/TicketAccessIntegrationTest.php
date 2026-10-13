<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Access;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\Attachment;
use Escalated\Symfony\Entity\ChatSession;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\DepartmentAgent;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\SideConversation;
use Escalated\Symfony\Entity\SideConversationReply;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\TicketActivity;
use Escalated\Symfony\Entity\TicketFollower;
use Escalated\Symfony\Entity\TicketLink;
use Escalated\Symfony\Security\TicketAccess;
use Escalated\Symfony\Service\AdvancedReportingService;
use Escalated\Symfony\Service\ApiTokenService;
use Escalated\Symfony\Service\AssignmentService;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Escalated\Symfony\Tests\Kernel\Fixtures\TestAccountResolver;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TicketAccessIntegrationTest extends EscalatedWebTestCase
{
    private function fixtures(array $config = []): array
    {
        $client = static::clientWithSchema(['escalated' => array_replace(['visibility' => 'department', 'user_class' => TestUser::class], $config)]);
        $em = static::entityManager();
        $agent = new TestUser('agent@example.com');
        $admin = new TestUser('admin@example.com', ['ROLE_ESCALATED_ADMIN']);
        $host = static::getContainer()->get('doctrine')->getManagerForClass(TestUser::class);
        $host->persist($agent);
        $host->persist($admin);
        $host->flush();
        $em->persist((new AgentProfile())->setUserId($agent->getId()));
        $department = (new Department())->setName('Allowed')->setSlug('allowed');
        $other = (new Department())->setName('Private')->setSlug('private');
        $em->persist($department);
        $em->persist($other);
        $em->flush();
        $em->persist(new DepartmentAgent($department, $agent->getId()));
        $visible = (new Ticket())->setSubject('Allowed')->setDepartment($department)->setAccount('Company', 'A');
        $hidden = (new Ticket())->setSubject('Secret')->setDepartment($other)->setAccount('Company', 'A');
        $unassigned = (new Ticket())->setSubject('Unassigned');
        foreach ([$visible, $hidden, $unassigned] as $ticket) {
            $em->persist($ticket);
        }
        $em->flush();

        return [$client, $agent, $admin, $visible, $hidden, $unassigned];
    }

    public function testDisjunctiveSearchCannotWidenVisibility(): void
    {
        [, $agent, , $visible] = $this->fixtures();
        $em = static::entityManager();
        static::getContainer()->get(TicketAccess::class)->runAs($agent, function () use ($em, $visible): void {
            $rows = $em->createQuery('SELECT t FROM '.Ticket::class.' t WHERE t.subject = :allowed OR t.subject = :secret')->setParameter('allowed', 'Allowed')->setParameter('secret', 'Secret')->getResult();
            self::assertSame([$visible->getId()], array_map(fn ($t) => $t->getId(), $rows));
        });
    }

    public function testReportsAreScopedBeforeCounting(): void
    {
        [, $agent] = $this->fixtures();
        static::getContainer()->get(TicketAccess::class)->runAs($agent, function (): void {
            $reports = static::getContainer()->get(AdvancedReportingService::class);
            $report = $reports->periodComparison(new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+1 day'));
            self::assertSame(1, $report['current']['total_created']);
            $trends = $reports->slaBreachTrends(new \DateTimeImmutable('today'), new \DateTimeImmutable('today'));
            self::assertSame(0, $trends[0]['frt_breaches']);
        });
    }

    public function testBulkQueriesUpdateOnlyVisibleTickets(): void
    {
        [, $agent, , $visible, $hidden] = $this->fixtures();
        $em = static::entityManager();
        static::getContainer()->get(TicketAccess::class)->runAs($agent, function () use ($em, $visible, $hidden): void {
            $count = $em->createQuery('UPDATE '.Ticket::class.' t SET t.priority = :priority WHERE t.id IN (:ids)')->setParameter('priority', 'urgent')->setParameter('ids', [$visible->getId(), $hidden->getId()])->execute();
            self::assertSame(1, $count);
        });
        $em->refresh($hidden);
        self::assertSame('medium', $hidden->getPriority());
        self::assertSame(3, $em->getRepository(Ticket::class)->count([]), 'the actor must be restored after scoped work');
    }

    public function testEveryMappedChildAndBothLinkEndsAreScoped(): void
    {
        [, $agent, , $visible, $hidden] = $this->fixtures();
        $em = static::entityManager();
        foreach ([$visible, $hidden] as $ticket) {
            $reply = (new Reply())->setTicket($ticket)->setBody('reply');
            $em->persist($reply);
            $em->persist((new TicketActivity())->setTicket($ticket)->setType('created'));
            $em->persist((new TicketFollower())->setTicketId($ticket->getId())->setUserId($agent->getId()));
            $em->persist((new ChatSession())->setTicket($ticket));
            $side = (new SideConversation())->setTicket($ticket)->setSubject('Internal');
            $em->persist($side);
            $em->persist((new SideConversationReply())->setSideConversation($side)->setBody('Secret reply'));
            $em->persist((new \Escalated\Symfony\Entity\SatisfactionRating())->setTicket($ticket)->setRating(5));
            $em->persist((new \Escalated\Symfony\Entity\TicketSubjectLink())->setTicket($ticket)->setSubjectType('Shipment')->setSubjectId('1'));

            $attachment = (new Attachment())->setReply($reply)->setStoredFilename('private.txt')->setOriginalFilename('private.txt')->setMimeType('text/plain')->setPath('/private')->setSize(1);
            $em->persist($attachment);
        }
        $em->persist((new TicketLink())->setParentTicket($visible)->setChildTicket($hidden));
        $em->flush();
        static::getContainer()->get(TicketAccess::class)->runAs($agent, function () use ($em): void {
            foreach ([Reply::class, TicketActivity::class, TicketFollower::class, ChatSession::class, Attachment::class, SideConversation::class, SideConversationReply::class, \Escalated\Symfony\Entity\SatisfactionRating::class, \Escalated\Symfony\Entity\TicketSubjectLink::class] as $class) {
                self::assertSame(1, $em->getRepository($class)->count([]), $class);
            }
            self::assertSame(0, $em->getRepository(TicketLink::class)->count([]));
        });
    }

    public function testRawMentionInboxAndUpdatesRespectVisibility(): void
    {
        [, $agent, , $visible, $hidden] = $this->fixtures();
        $em = static::entityManager();
        $ids = [];
        $table = new \Doctrine\DBAL\Schema\Table('escalated_mentions');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->setPrimaryKey(['id']);
        $table->addColumn('reply_id', 'integer');
        $table->addColumn('user_id', 'integer');
        $table->addColumn('created_at', 'datetime_immutable');
        $table->addColumn('read_at', 'datetime_immutable', ['notnull' => false]);
        $em->getConnection()->createSchemaManager()->createTable($table);
        foreach ([$visible, $hidden] as $ticket) {
            $reply = (new Reply())->setTicket($ticket)->setBody('Mention');
            $em->persist($reply);
            $em->flush();
            $em->getConnection()->insert('escalated_mentions', ['reply_id' => $reply->getId(), 'user_id' => $agent->getId(), 'created_at' => '2026-10-13 12:00:00']);
        }
        $ids = $em->getConnection()->fetchFirstColumn('SELECT id FROM escalated_mentions');
        static::getContainer()->get(TicketAccess::class)->runAs($agent, function () use ($agent, $ids): void {
            $mentions = new \Escalated\Symfony\Service\MentionService(static::entityManager(), access: static::getContainer()->get(TicketAccess::class));
            self::assertCount(1, $mentions->unreadMentions($agent->getId()));
            $mentions->markAsRead($ids, $agent->getId());
        });
        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM escalated_mentions WHERE read_at IS NULL'));
    }

    public function testPreloadedTicketServicesAndRepositoryFindRejectHiddenEntities(): void
    {
        [, $agent, , , $hidden] = $this->fixtures();
        $em = static::entityManager();
        $access = static::getContainer()->get(TicketAccess::class);
        $access->runAs($agent, function () use ($em, $hidden): void {
            self::assertNull($em->getRepository(Ticket::class)->find($hidden->getId()));
            $this->expectException(NotFoundHttpException::class);
            static::getContainer()->get(TicketService::class)->update($hidden, ['subject' => 'Stolen']);
        });
    }

    public function testDirectFlushCannotEditAnEntityLoadedBeforeTheActor(): void
    {
        [, $agent, , , $hidden] = $this->fixtures();
        static::getContainer()->get(TicketAccess::class)->runAs($agent, function () use ($hidden): void {
            $hidden->setSubject('Stolen');
            $this->expectException(NotFoundHttpException::class);
            static::entityManager()->flush();
        });
    }

    public function testMovingAPreloadedChildOutOfAHiddenTicketIsRejected(): void
    {
        [, $agent, , $visible, $hidden] = $this->fixtures();
        $em = static::entityManager();
        $reply = (new Reply())->setTicket($hidden)->setBody('secret');
        $em->persist($reply);
        $em->flush();
        static::getContainer()->get(TicketAccess::class)->runAs($agent, function () use ($em, $reply, $visible): void {
            $reply->setTicket($visible);
            $this->expectException(NotFoundHttpException::class);
            $em->flush();
        });
    }

    public function testAdminDefaultOffAndNestedContextsRetainAccess(): void
    {
        [, $agent, $admin] = $this->fixtures();
        $em = static::entityManager();
        $access = static::getContainer()->get(TicketAccess::class);
        $access->runAs($agent, function () use ($em, $admin, $access): void {
            self::assertSame(1, $em->getRepository(Ticket::class)->count([]));
            $access->runAs($admin, fn () => self::assertSame(3, $em->getRepository(Ticket::class)->count([])));
            self::assertSame(1, $em->getRepository(Ticket::class)->count([]));
        });
        self::assertSame(3, $em->getRepository(Ticket::class)->count([]));
        static::ensureKernelShutdown();
        [, $agent] = $this->fixtures(['visibility' => 'all']);
        static::getContainer()->get(TicketAccess::class)->runAs($agent, fn () => self::assertSame(3, static::entityManager()->getRepository(Ticket::class)->count([])));
    }

    public function testBearerOwnerCannotBorrowTheSessionAdminsDepartmentsOrAdminAbility(): void
    {
        [$client, $agent, $admin] = $this->fixtures();
        $token = static::getContainer()->get(ApiTokenService::class)->createToken($agent->getId(), 'access', ['*']);
        $client->loginUser($admin);
        $client->jsonRequest('GET', '/support/api/v1/tickets', [], ['HTTP_AUTHORIZATION' => 'Bearer '.$token['plainTextToken']]);
        self::assertResponseIsSuccessful();
        self::assertCount(1, json_decode($client->getResponse()->getContent(), true)['data']);
    }

    public function testAccountAndDepartmentIntersectAndIdentityIsCaseSensitive(): void
    {
        [, $agent, $admin, $visible] = $this->fixtures(['accounts' => ['enabled' => true, 'scope_agents' => true, 'resolver' => TestAccountResolver::class]]);
        $em = static::entityManager();
        $resolver = static::getContainer()->get(TestAccountResolver::class);
        $resolver->accounts['agent:'.$agent->getId()] = ['type' => 'Company', 'id' => 'A'];
        $wrong = (new Ticket())->setSubject('Wrong case')->setDepartment($visible->getDepartment())->setAccount('company', 'A');
        $em->persist($wrong);
        $em->persist((new Ticket())->setSubject('Trailing space')->setDepartment($visible->getDepartment())->setAccount('Company', 'A '));
        $em->flush();
        $access = static::getContainer()->get(TicketAccess::class);
        $access->runAs($agent, fn () => self::assertSame(1, $em->getRepository(Ticket::class)->count([])));
        $resolver->accounts = [];
        $access->runAs($agent, fn () => self::assertSame(0, $em->getRepository(Ticket::class)->count([])));
        $access->runAs($admin, fn () => self::assertSame(5, $em->getRepository(Ticket::class)->count([])));
    }

    public function testAssignmentsRecipientsAndQueuedBroadcastsRecheckRevokedMembership(): void
    {
        [, $agent, $admin, $visible, $hidden] = $this->fixtures();
        $access = static::getContainer()->get(TicketAccess::class);
        self::assertSame([(string) $agent->getId(), (string) $admin->getId()], $access->recipients($visible, [$agent->getId(), $admin->getId(), $agent->getId()]));
        self::assertSame([(string) $admin->getId()], $access->recipients($hidden, [$agent->getId(), $admin->getId()]));
        $transport = new class implements \Escalated\Symfony\Broadcasting\AuthorizedBroadcasterInterface {
            public $canReceive;

            public function broadcast(\Escalated\Symfony\Broadcasting\BroadcastableEvent $event): void
            {
                throw new \LogicException('Legacy delivery must not be called.');
            }

            public function broadcastAuthorized(\Escalated\Symfony\Broadcasting\BroadcastableEvent $event, callable $canReceive): void
            {
                $this->canReceive = $canReceive;
            }
        };
        $delivery = new \Escalated\Symfony\Broadcasting\TicketBroadcastDelivery($transport, $access, static::entityManager());
        $delivery->broadcast(new \Escalated\Symfony\Broadcasting\BroadcastableEvent('ticket.updated', 'agents', ['ticket_id' => $visible->getId()]));
        self::assertTrue(($transport->canReceive)($agent->getId()));
        static::entityManager()->createQuery('DELETE FROM '.DepartmentAgent::class.' m WHERE m.agentId = :id')->setParameter('id', $agent->getId())->execute();
        self::assertFalse(($transport->canReceive)($agent->getId()));
        $this->expectException(\InvalidArgumentException::class);
        static::getContainer()->get(AssignmentService::class)->assign($visible, $agent->getId());
    }

    public function testSeparateHostDatabaseUsesOnlyPackageMembershipTables(): void
    {
        [$client, $agent, , $visible] = $this->fixtures(['entity_manager' => 'support', 'table_prefix' => 'support_']);
        $client->loginUser($agent);
        $client->jsonRequest('GET', '/support/api/v1/tickets');
        self::assertResponseIsSuccessful();
        self::assertCount(1, json_decode($client->getResponse()->getContent(), true)['data']);
        self::assertFalse(static::entityManager()->getConnection()->createSchemaManager()->tablesExist(['test_users']));
        self::assertTrue(static::getContainer()->get(TicketAccess::class)->canReceive($visible, $agent->getId()));
    }

    public function testCustomTablePrefixScopesOrmAndRawWorkflowQueries(): void
    {
        [, $agent, , $visible] = $this->fixtures(['table_prefix' => 'support_']);
        $access = static::getContainer()->get(TicketAccess::class);
        $access->runAs($agent, fn () => self::assertSame(1, static::entityManager()->getRepository(Ticket::class)->count([])));
        static::getContainer()->get(TicketService::class)->update($visible, ['subject' => 'Updated']);
        self::assertTrue(static::entityManager()->getConnection()->createSchemaManager()->tablesExist(['support_tickets', 'support_department_agent']));
    }
}
