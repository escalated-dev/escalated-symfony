<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Access;

use Doctrine\Migrations\DependencyFactory;
use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\DepartmentAgent;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Security\ApiTokenUser;
use Escalated\Symfony\Security\TicketAccess;
use Escalated\Symfony\Tests\Kernel\EscalatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class TicketAccessMigrationTest extends EscalatedKernelTestCase
{
    public static function prefixes(): iterable
    {
        yield 'custom' => ['support_'];
        yield 'mixed case' => ['Support_'];
    }

    #[DataProvider('prefixes')]
    public function testUpgradeRollbackAndUuidMembershipPreserveLegacyData(string $prefix): void
    {
        $previous = $_ENV['ESCALATED_USER_KEY_TYPE'] ?? null;
        $_ENV['ESCALATED_USER_KEY_TYPE'] = 'uuid';
        try {
            self::bootKernel(['host_user' => false, 'escalated' => ['table_prefix' => $prefix, 'visibility' => 'department']]);
            self::createSchema();
            $em = self::entityManager();
            $conn = $em->getConnection();
            $schemaManager = $conn->createSchemaManager();
            $ticket = (new Ticket())->setSubject('Legacy row');
            $em->persist($ticket);
            $em->flush();
            $id = $ticket->getId();
            $em->clear();
            $factory = self::getContainer()->get('doctrine.migrations.dependency_factory');
            self::assertInstanceOf(DependencyFactory::class, $factory);
            $factory->getMigrationRepository()->getMigrations();
            $migration = $factory->getMigrationFactory()->createVersion('Escalated\\Symfony\\Migrations\\Version20261013000001');
            // Use the factory-configured prefix and real DBAL schema SQL in both directions.
            foreach (['down', 'up'] as $direction) {
                $before = $schemaManager->introspectSchema();
                $after = clone $before;
                $migration->$direction($after);
                $diff = $schemaManager->createComparator()->compareSchemas($before, $after);
                foreach ($conn->getDatabasePlatform()->getAlterSchemaSQL($diff) as $sql) {
                    $conn->executeStatement($sql);
                }
                self::assertSame('Legacy row', $conn->fetchOne('SELECT subject FROM '.$prefix.'tickets WHERE id = ?', [$id]));
            }
            $ticket = $em->getRepository(Ticket::class)->find($id);
            self::assertNull($ticket->getAccountId());
            $department = (new Department())->setName('Support')->setSlug('support');
            $em->persist($department);
            $em->flush();
            $agentId = 'bdf6e858-f4ec-434e-925f-2f5d2adcf547';
            $em->persist(new DepartmentAgent($department, $agentId));
            $em->persist((new AgentProfile())->setUserId($agentId));
            $ticket->setDepartment($department);
            $em->flush();
            self::getContainer()->get(TicketAccess::class)->runAs(new ApiTokenUser($agentId, ['ROLE_ESCALATED_AGENT']), function () use ($em): void {
                self::assertSame(1, $em->getRepository(Ticket::class)->count([]));
            });
            self::getContainer()->get(TicketAccess::class)->runAs(new ApiTokenUser(strtoupper($agentId), ['ROLE_ESCALATED_AGENT']), function () use ($em): void {
                self::assertSame(0, $em->getRepository(Ticket::class)->count([]));
            });
            $em->persist(new DepartmentAgent($department, '007'));
            $em->flush();
            self::getContainer()->get(TicketAccess::class)->runAs(new ApiTokenUser('007', ['ROLE_ESCALATED_AGENT']), function () use ($em): void {
                self::assertSame(1, $em->getRepository(Ticket::class)->count([]));
            });
            self::getContainer()->get(TicketAccess::class)->runAs(new ApiTokenUser('7', ['ROLE_ESCALATED_AGENT']), function () use ($em): void {
                self::assertSame(0, $em->getRepository(Ticket::class)->count([]));
            });
        } finally {
            self::ensureKernelShutdown();
            if (null === $previous) {
                unset($_ENV['ESCALATED_USER_KEY_TYPE']);
            } else {
                $_ENV['ESCALATED_USER_KEY_TYPE'] = $previous;
            }
        }
    }
}
