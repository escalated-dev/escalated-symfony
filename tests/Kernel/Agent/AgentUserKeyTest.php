<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Agent;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\CannedResponse;
use Escalated\Symfony\Entity\ChatSession;
use Escalated\Symfony\Entity\Macro;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Service\ChatSessionService;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Agent endpoints must act as the agent's host user key (its primary key),
 * not getUserIdentifier() -- which for a session user is the email.
 *
 * Live chat accept/message/end passed the email into int parameters and
 * failed with a TypeError (500) for every agent. Macros and canned
 * responses fell back to agent id 0: personal ones never listed, and macro
 * replies were authored by user 0.
 */
final class AgentUserKeyTest extends EscalatedWebTestCase
{
    private KernelBrowser $client;

    private TestUser $agent;

    protected function setUp(): void
    {
        $this->client = static::clientWithSchema();
        $em = static::entityManager();
        $this->agent = new TestUser('agent@example.com');
        $em->persist($this->agent);
        $em->flush();
        $profile = new AgentProfile();
        $profile->setUserId($this->agent->getId());
        $em->persist($profile);
        $em->flush();
        $this->client->loginUser($this->agent);
    }

    public function testAgentCanAcceptMessageAndEndALiveChat(): void
    {
        $started = static::getContainer()->get(ChatSessionService::class)->startSession(['guest_name' => 'Visitor', 'message' => 'Hello']);
        $ticket = $started['ticket'];
        \assert($ticket instanceof Ticket);
        $id = $ticket->getId();
        $agentId = $this->agent->getId();

        $this->client->jsonRequest('POST', "/support/agent/chat/sessions/{$id}/accept");
        self::assertResponseIsSuccessful();
        $session = static::entityManager()->getRepository(ChatSession::class)->findOneBy(['ticket' => $id]);
        self::assertSame($agentId, $session->getAgentId());
        self::assertSame($agentId, $ticket->getAssignedTo());

        $this->client->jsonRequest('POST', "/support/agent/chat/sessions/{$id}/message", ['body' => 'How can I help?']);
        self::assertResponseStatusCodeSame(201);
        $reply = static::entityManager()->getRepository(Reply::class)->findOneBy(['body' => 'How can I help?']);
        self::assertNotNull($reply);
        self::assertSame($agentId, $reply->getAuthorId());

        $this->client->jsonRequest('POST', "/support/agent/chat/sessions/{$id}/end");
        self::assertResponseIsSuccessful();
        static::entityManager()->clear();
        $ended = static::entityManager()->getRepository(Ticket::class)->find($id);
        self::assertSame($agentId, $ended->getChatMetadata()['ended_by'] ?? null);
    }

    public function testPersonalMacrosListAndMacroRepliesAreAuthoredByTheAgent(): void
    {
        $em = static::entityManager();
        $own = (new Macro())->setName('Mine')->setIsShared(false)->setCreatedBy($this->agent->getId())
            ->setActions([['type' => 'add_reply', 'value' => 'Macro reply']]);
        $other = (new Macro())->setName('Someone else')->setIsShared(false)->setCreatedBy(999)->setActions([]);
        $ticket = (new Ticket())->setSubject('Needs a macro');
        $em->persist($own);
        $em->persist($other);
        $em->persist($ticket);
        $em->flush();

        $this->client->jsonRequest('GET', '/support/agent/macros');
        self::assertResponseIsSuccessful();
        self::assertSame(['Mine'], array_column(json_decode($this->client->getResponse()->getContent(), true), 'name'));

        $this->client->jsonRequest('POST', sprintf('/support/agent/tickets/%d/macros/%d/apply', $ticket->getId(), $own->getId()));
        self::assertResponseIsSuccessful();
        $reply = $em->getRepository(Reply::class)->findOneBy(['body' => 'Macro reply']);
        self::assertNotNull($reply);
        self::assertSame($this->agent->getId(), $reply->getAuthorId());
    }

    public function testPersonalCannedResponsesList(): void
    {
        $em = static::entityManager();
        $em->persist((new CannedResponse())->setTitle('Mine')->setBody('x')->setIsShared(false)->setCreatedBy($this->agent->getId()));
        $em->persist((new CannedResponse())->setTitle('Someone else')->setBody('y')->setIsShared(false)->setCreatedBy(999));
        $em->flush();

        $this->client->jsonRequest('GET', '/support/agent/canned-responses');
        self::assertResponseIsSuccessful();
        self::assertSame(['Mine'], array_column(json_decode($this->client->getResponse()->getContent(), true), 'title'));
    }
}
