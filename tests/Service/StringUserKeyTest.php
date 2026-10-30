<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Escalated\Symfony\Broadcasting\BroadcasterInterface;
use Escalated\Symfony\Broadcasting\TicketBroadcastSubscriber;
use Escalated\Symfony\Dto\SkillWriteInput;
use Escalated\Symfony\Entity\ChatRoutingRule;
use Escalated\Symfony\Entity\ChatSession;
use Escalated\Symfony\Entity\Macro;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\TwoFactor;
use Escalated\Symfony\Service\ChatRoutingService;
use Escalated\Symfony\Service\ChatSessionService;
use Escalated\Symfony\Service\MacroService;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Service\TwoFactorService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Hosts with UUID/string user keys (ESCALATED_USER_KEY_TYPE=uuid|string)
 * must be able to use live chat, macros, skills, two-factor and agent
 * broadcast channels: these took `int` user keys and threw a TypeError, cast
 * the id to 0, or rejected it outright.
 */
class StringUserKeyTest extends TestCase
{
    private const UUID = '0192b7a4-5c1e-7d2f-8a3b-4c5d6e7f8a9b';

    private string|false $previousKeyType;

    protected function setUp(): void
    {
        $this->previousKeyType = getenv('ESCALATED_USER_KEY_TYPE');
        $_ENV['ESCALATED_USER_KEY_TYPE'] = 'uuid';
        putenv('ESCALATED_USER_KEY_TYPE=uuid');
    }

    protected function tearDown(): void
    {
        unset($_ENV['ESCALATED_USER_KEY_TYPE']);
        false === $this->previousKeyType
            ? putenv('ESCALATED_USER_KEY_TYPE')
            : putenv('ESCALATED_USER_KEY_TYPE='.$this->previousKeyType);
    }

    public function testChatAcceptMessageAndEndKeepAUuidAgentKey(): void
    {
        $ticket = (new Ticket())->setSubject('Chat');
        $session = new ChatSession();
        $session->setTicket($ticket);

        $tickets = $this->createMock(TicketService::class);
        $tickets->expects($this->once())->method('addReply')
            ->with($ticket, self::UUID, 'Hi', false, 'App\\User')
            ->willReturn((new Reply())->setBody('Hi'));

        $service = new ChatSessionService(
            $this->createMock(EntityManagerInterface::class),
            $tickets,
            $this->createMock(ChatRoutingService::class),
            $this->createMock(BroadcasterInterface::class),
        );

        $service->assignAgent($session, self::UUID);
        $this->assertSame(self::UUID, $session->getAgentId());
        $this->assertSame(self::UUID, $ticket->getAssignedTo());

        $service->sendMessage($session, 'Hi', self::UUID, 'App\\User');

        $service->endSession($session, self::UUID);
        $this->assertSame(self::UUID, $ticket->getChatMetadata()['ended_by']);
    }

    public function testChatRoutingKeepsUuidAgentIds(): void
    {
        $routing = new class($this->createMock(EntityManagerInterface::class)) extends ChatRoutingService {
            public function getAgentActiveChatCount(int|string $agentId): int
            {
                return 0;
            }
        };
        $rule = (new ChatRoutingRule())->setAgentIds([self::UUID])->setMaxConcurrentChats(3);

        $agent = (new \ReflectionMethod(ChatRoutingService::class, 'evaluateRule'))->invoke($routing, $rule, null);

        $this->assertSame(self::UUID, $agent);
    }

    public function testMacroRepliesAreAuthoredByAUuidAgent(): void
    {
        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $macro = new Macro();
        $macro->setActions([['type' => 'add_reply', 'value' => 'From a macro']]);

        (new MacroService($em, new NullLogger()))->apply($macro, new Ticket(), self::UUID);

        $this->assertCount(1, $persisted);
        $this->assertInstanceOf(Reply::class, $persisted[0]);
        $this->assertSame(self::UUID, $persisted[0]->getAuthorId());
    }

    public function testMacroAndCannedResponseCreatorsKeepAUuidKey(): void
    {
        $this->assertSame(self::UUID, (new Macro())->setCreatedBy(self::UUID)->getCreatedBy());
        $this->assertSame(self::UUID, (new \Escalated\Symfony\Entity\CannedResponse())->setCreatedBy(self::UUID)->getCreatedBy());
    }

    public function testSkillAgentsAcceptUuidUserIds(): void
    {
        $dto = SkillWriteInput::fromPayload([
            'name' => 'Networking',
            'agents' => [['user_id' => self::UUID, 'proficiency' => 4]],
        ]);

        $this->assertSame([['user_id' => self::UUID, 'proficiency' => 4]], $dto->agents);
    }

    public function testTwoFactorAcceptsAUuidUserKey(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects($this->once())->method('findOneBy')
            ->with(['userId' => self::UUID, 'isEnabled' => true])
            ->willReturn(null);
        $repository->method('findBy')->willReturn([]);
        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(TwoFactor::class)->willReturn($repository);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $service = new TwoFactorService($em, new \Escalated\Symfony\Security\SecretSealer('test-secret'));

        $service->begin(self::UUID);
        $this->assertInstanceOf(TwoFactor::class, $persisted[0] ?? null);
        $this->assertSame(self::UUID, $persisted[0]->getUserId());
        $this->assertNull($service->confirmedFor(self::UUID));
    }

    public function testMentionsAcceptAUuidUserKey(): void
    {
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->expects($this->once())->method('fetchAllAssociative')
            ->with($this->anything(), [self::UUID])
            ->willReturn([]);
        $connection->expects($this->once())->method('executeStatement')
            ->with($this->anything(), $this->callback(static fn (array $params): bool => self::UUID === end($params)));
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $service = new \Escalated\Symfony\Service\MentionService($em);

        $this->assertSame([], $service->unreadMentions(self::UUID));
        $service->markAsRead([1], self::UUID);
    }

    public function testAgentBroadcastChannelUsesTheUuidKey(): void
    {
        $this->assertSame('agent.'.self::UUID, TicketBroadcastSubscriber::channelForAgent(self::UUID));
        $this->assertSame('agent.7', TicketBroadcastSubscriber::channelForAgent(7));
    }
}
