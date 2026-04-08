<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\Tag;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Repository\TicketRepository;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Service\TicketSplittingService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class TicketSplittingServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private TicketService $ticketService;
    private TicketSplittingService $splittingService;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $ticketRepository = $this->createMock(TicketRepository::class);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->ticketService = new TicketService($this->em, $ticketRepository, $dispatcher);
        $this->splittingService = new TicketSplittingService($this->em, $this->ticketService);
    }

    public function testSplitTicketCreatesNewTicketFromReply(): void
    {
        $this->em->expects($this->atLeastOnce())->method('persist');
        $this->em->expects($this->atLeastOnce())->method('flush');

        $original = new Ticket();
        $original->setSubject('Original issue');
        $original->setReference('ESC-00001');
        $original->setPriority(Ticket::PRIORITY_HIGH);
        $original->setRequesterId(42);
        $original->setRequesterClass('App\Entity\User');

        $reply = new Reply();
        $reply->setTicket($original);
        $reply->setBody('This should be a separate issue.');
        $reply->setAuthorId(1);
        $original->addReply($reply);

        $newTicket = $this->splittingService->splitTicket($original, $reply, 1);

        $this->assertSame('This should be a separate issue.', $newTicket->getDescription());
        $this->assertSame(Ticket::PRIORITY_HIGH, $newTicket->getPriority());
        $this->assertSame(42, $newTicket->getRequesterId());
        $this->assertSame('App\Entity\User', $newTicket->getRequesterClass());
        $this->assertSame(Ticket::STATUS_OPEN, $newTicket->getStatus());
    }

    public function testSplitTicketWithCustomSubject(): void
    {
        $this->em->expects($this->atLeastOnce())->method('persist');
        $this->em->expects($this->atLeastOnce())->method('flush');

        $original = new Ticket();
        $original->setSubject('Original');
        $original->setReference('ESC-00002');

        $reply = new Reply();
        $reply->setTicket($original);
        $reply->setBody('Reply content.');
        $original->addReply($reply);

        $newTicket = $this->splittingService->splitTicket($original, $reply, 1, [
            'subject' => 'Custom split subject',
        ]);

        $this->assertSame('Custom split subject', $newTicket->getSubject());
    }

    public function testSplitTicketLinksViaMetadata(): void
    {
        $this->em->expects($this->atLeastOnce())->method('persist');
        $this->em->expects($this->atLeastOnce())->method('flush');

        $original = new Ticket();
        $original->setSubject('Original');
        $original->setReference('ESC-00003');

        $reply = new Reply();
        $reply->setTicket($original);
        $reply->setBody('Content.');
        $original->addReply($reply);

        $newTicket = $this->splittingService->splitTicket($original, $reply, 1);

        $originalMeta = $original->getMetadata();
        $newMeta = $newTicket->getMetadata();

        $this->assertIsArray($originalMeta);
        $this->assertArrayHasKey('split_to', $originalMeta);
        $this->assertContains($newTicket->getReference(), $originalMeta['split_to']);

        $this->assertIsArray($newMeta);
        $this->assertSame('ESC-00003', $newMeta['split_from']);
    }

    public function testSplitTicketCopiesTags(): void
    {
        $this->em->expects($this->atLeastOnce())->method('persist');
        $this->em->expects($this->atLeastOnce())->method('flush');

        $tag = new Tag();
        $tag->setName('bug');
        $tag->setSlug('bug');

        $original = new Ticket();
        $original->setSubject('Original');
        $original->setReference('ESC-00004');
        $original->addTag($tag);

        $reply = new Reply();
        $reply->setTicket($original);
        $reply->setBody('Content.');
        $original->addReply($reply);

        $newTicket = $this->splittingService->splitTicket($original, $reply, 1);

        $this->assertCount(1, $newTicket->getTags());
        $this->assertSame($tag, $newTicket->getTags()->first());
    }

    public function testSplitTicketRejectsReplyFromDifferentTicket(): void
    {
        $original = new Ticket();
        $original->setSubject('Original');

        $otherTicket = new Ticket();
        $otherTicket->setSubject('Other');

        $reply = new Reply();
        $reply->setTicket($otherTicket);
        $reply->setBody('Content.');

        $this->expectException(\InvalidArgumentException::class);

        $this->splittingService->splitTicket($original, $reply, 1);
    }

    public function testSplitTicketLogsActivityOnBothTickets(): void
    {
        $this->em->expects($this->atLeastOnce())->method('persist');
        $this->em->expects($this->atLeastOnce())->method('flush');

        $original = new Ticket();
        $original->setSubject('Original');
        $original->setReference('ESC-00005');

        $reply = new Reply();
        $reply->setTicket($original);
        $reply->setBody('Content.');
        $original->addReply($reply);

        $newTicket = $this->splittingService->splitTicket($original, $reply, 1);

        $originalActivities = $original->getActivities();
        $splitToActivity = null;
        foreach ($originalActivities as $activity) {
            if (TicketSplittingService::ACTIVITY_TYPE_SPLIT_TO === $activity->getType()) {
                $splitToActivity = $activity;
                break;
            }
        }
        $this->assertNotNull($splitToActivity);

        $newActivities = $newTicket->getActivities();
        $splitFromActivity = null;
        foreach ($newActivities as $activity) {
            if (TicketSplittingService::ACTIVITY_TYPE_SPLIT_FROM === $activity->getType()) {
                $splitFromActivity = $activity;
                break;
            }
        }
        $this->assertNotNull($splitFromActivity);
    }

    public function testGetSplitChildrenAndParent(): void
    {
        $parent = new Ticket();
        $parent->setMetadata(['split_to' => ['ESC-00010', 'ESC-00011']]);

        $child = new Ticket();
        $child->setMetadata(['split_from' => 'ESC-00001']);

        $this->assertSame(['ESC-00010', 'ESC-00011'], $this->splittingService->getSplitChildren($parent));
        $this->assertSame('ESC-00001', $this->splittingService->getSplitParent($child));
    }

    public function testGetSplitChildrenReturnsEmptyArrayWhenNoSplits(): void
    {
        $ticket = new Ticket();

        $this->assertSame([], $this->splittingService->getSplitChildren($ticket));
        $this->assertNull($this->splittingService->getSplitParent($ticket));
    }
}
