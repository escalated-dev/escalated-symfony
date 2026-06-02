<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Escalated\Symfony\Contract\TicketSubject;
use Escalated\Symfony\Contract\TicketSubjectResolverInterface;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\TicketSubjectLink;
use Escalated\Symfony\Service\TicketSubjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TicketSubjectServiceTest extends TestCase
{
    private const SUBJECT_TYPE = 'App\\Entity\\FakeProject';

    private EntityManagerInterface&MockObject $em;
    private EntityRepository&MockObject $linkRepository;
    private QueryBuilder&MockObject $qb;
    private Query&MockObject $query;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->linkRepository = $this->createMock(EntityRepository::class);
        $this->qb = $this->createMock(QueryBuilder::class);
        $this->query = $this->createMock(Query::class);

        $this->em->method('getRepository')
            ->with(TicketSubjectLink::class)
            ->willReturn($this->linkRepository);

        $this->em->method('createQueryBuilder')->willReturn($this->qb);
        $this->qb->method('select')->willReturnSelf();
        $this->qb->method('from')->willReturnSelf();
        $this->qb->method('where')->willReturnSelf();
        $this->qb->method('setParameter')->willReturnSelf();
        $this->qb->method('getQuery')->willReturn($this->query);
        $this->query->method('getSingleScalarResult')->willReturn(-1);
    }

    private function makeService(
        array $allowedTypes = [self::SUBJECT_TYPE],
        ?TicketSubjectResolverInterface $resolver = null,
    ): TicketSubjectService {
        return new TicketSubjectService($this->em, $allowedTypes, $resolver);
    }

    public function testAttachPreservesStringId(): void
    {
        $service = $this->makeService();
        $ticket = $this->createTicket(1);

        $this->linkRepository->method('findOneBy')->willReturn(null);
        $this->em->expects($this->once())->method('persist')->with($this->callback(
            static fn (TicketSubjectLink $link): bool => 'prj_9f1c' === $link->getSubjectId()
                && self::SUBJECT_TYPE === $link->getSubjectType()
                && 'project' === $link->getRole(),
        ));
        $this->em->expects($this->once())->method('flush');

        $link = $service->attach($ticket, self::SUBJECT_TYPE, 'prj_9f1c', 'project');

        $this->assertSame('prj_9f1c', $link->getSubjectId());
        $this->assertSame('project', $link->getRole());
    }

    public function testAttachIsIdempotentAndUpdatesRole(): void
    {
        $service = $this->makeService();
        $ticket = $this->createTicket(1);

        $existing = new TicketSubjectLink();
        $existing->setTicket($ticket);
        $existing->setSubjectType(self::SUBJECT_TYPE);
        $existing->setSubjectId('p1');
        $existing->setRole(null);

        $this->linkRepository->method('findOneBy')->willReturn($existing);
        $this->em->expects($this->once())->method('flush');
        $this->em->expects($this->never())->method('persist');

        $link = $service->attach($ticket, self::SUBJECT_TYPE, 'p1', 'account');

        $this->assertSame('account', $link->getRole());
    }

    public function testDetachByLinkId(): void
    {
        $service = $this->makeService();
        $ticket = $this->createTicket(1);
        $link = new TicketSubjectLink();
        $link->setTicket($ticket);

        $this->linkRepository->method('findOneBy')->willReturn($link);
        $this->em->expects($this->once())->method('remove')->with($link);
        $this->em->expects($this->once())->method('flush');

        $service->detach($ticket, 9);
    }

    public function testSyncReplacesSubjectsInOrder(): void
    {
        $service = $this->makeService();
        $ticket = $this->createTicket(1);

        $this->linkRepository->method('findBy')->willReturn([]);
        $this->em->expects($this->exactly(2))->method('flush');
        $this->em->expects($this->exactly(2))->method('persist');

        $links = $service->sync($ticket, [
            ['subjectType' => self::SUBJECT_TYPE, 'subjectId' => 'b', 'role' => 'primary'],
            ['subjectType' => self::SUBJECT_TYPE, 'subjectId' => 'c'],
        ]);

        $this->assertCount(2, $links);
        $this->assertSame('b', $links[0]->getSubjectId());
        $this->assertSame('primary', $links[0]->getRole());
        $this->assertSame(0, $links[0]->getPosition());
        $this->assertSame('c', $links[1]->getSubjectId());
        $this->assertSame(1, $links[1]->getPosition());
    }

    public function testRejectsTypeOutsideAllowlist(): void
    {
        $service = $this->makeService([self::SUBJECT_TYPE]);
        $ticket = $this->createTicket(1);

        $this->expectException(\InvalidArgumentException::class);
        $service->attach($ticket, 'OtherType', '1');
    }

    public function testAllowsAnyTypeWhenAllowlistEmpty(): void
    {
        $service = $this->makeService([]);
        $ticket = $this->createTicket(1);

        $this->linkRepository->method('findOneBy')->willReturn(null);
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $link = $service->attach($ticket, self::SUBJECT_TYPE, '1');

        $this->assertSame('1', $link->getSubjectId());
    }

    public function testApiAssertRejectsWhenAllowlistEmpty(): void
    {
        $service = $this->makeService([]);

        $this->expectException(\InvalidArgumentException::class);
        $service->assertApiTypeAllowed(self::SUBJECT_TYPE);
    }

    public function testSerializeThroughResolver(): void
    {
        $resolver = new class implements TicketSubjectResolverInterface {
            public function resolve(string $type, string $id): ?TicketSubject
            {
                return new class('Acme Redesign', $id) implements TicketSubject {
                    public function __construct(private string $title, private string $id)
                    {
                    }

                    public function ticketSubjectTitle(): string
                    {
                        return $this->title;
                    }

                    public function ticketSubjectSubtitle(): ?string
                    {
                        return 'Project · Acme';
                    }

                    public function ticketSubjectUrl(): ?string
                    {
                        return 'https://app.test/projects/'.$this->id;
                    }

                    public function ticketSubjectColor(): ?string
                    {
                        return '#2563eb';
                    }

                    public function ticketSubjectIcon(): ?string
                    {
                        return 'folder';
                    }
                };
            }
        };

        $service = $this->makeService([self::SUBJECT_TYPE], $resolver);

        $link = new TicketSubjectLink();
        $link->setSubjectType(self::SUBJECT_TYPE);
        $link->setSubjectId('7');
        $link->setRole('project');

        $serialized = $service->serializeLinks([$link]);

        $this->assertSame([
            'type' => self::SUBJECT_TYPE,
            'id' => '7',
            'role' => 'project',
            'title' => 'Acme Redesign',
            'subtitle' => 'Project · Acme',
            'url' => 'https://app.test/projects/7',
            'color' => '#2563eb',
            'icon' => 'folder',
            'missing' => false,
        ], $serialized[0]);
    }

    public function testSerializeFallbackWhenResolverMissing(): void
    {
        $service = $this->makeService([self::SUBJECT_TYPE]);

        $link = new TicketSubjectLink();
        $link->setSubjectType(self::SUBJECT_TYPE);
        $link->setSubjectId('99');

        $serialized = $service->serializeLinks([$link])[0];

        $this->assertSame(self::SUBJECT_TYPE.'#99', $serialized['title']);
        $this->assertNull($serialized['subtitle']);
        $this->assertTrue($serialized['missing']);
    }

    private function createTicket(int $id): Ticket
    {
        $ticket = new Ticket();
        $reflection = new \ReflectionProperty(Ticket::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($ticket, $id);

        return $ticket;
    }
}
