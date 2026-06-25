<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\SideConversation;
use Escalated\Symfony\Entity\SideConversationReply;
use Escalated\Symfony\Entity\Ticket;

/**
 * Manages side conversations (private internal/email threads on a ticket).
 * Mirrors the Laravel SideConversationController: creating a conversation
 * opens it with a first reply, replies can be appended, and a conversation
 * can be closed.
 */
class SideConversationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Whether a channel value is accepted. Pure.
     */
    public function isValidChannel(string $channel): bool
    {
        return \in_array($channel, SideConversation::CHANNELS, true);
    }

    /**
     * Open a new side conversation on a ticket with its first reply.
     *
     * @throws \InvalidArgumentException on empty subject/body or invalid channel
     */
    public function create(
        Ticket $ticket,
        string $subject,
        string $channel,
        string $body,
        ?string $createdBy = null,
    ): SideConversation {
        $subject = trim($subject);
        $body = trim($body);

        if ('' === $subject) {
            throw new \InvalidArgumentException('Subject is required.');
        }
        if (!$this->isValidChannel($channel)) {
            throw new \InvalidArgumentException('Invalid channel.');
        }
        if ('' === $body) {
            throw new \InvalidArgumentException('Body is required.');
        }

        $conversation = (new SideConversation())
            ->setTicket($ticket)
            ->setSubject($subject)
            ->setChannel($channel)
            ->setStatus(SideConversation::STATUS_OPEN)
            ->setCreatedBy($createdBy);
        $this->em->persist($conversation);

        $reply = (new SideConversationReply())
            ->setBody($body)
            ->setAuthorId($createdBy);
        $conversation->addReply($reply);
        $this->em->persist($reply);

        $this->em->flush();

        return $conversation;
    }

    /**
     * Append a reply to a conversation.
     *
     * @throws \InvalidArgumentException on empty body
     */
    public function addReply(
        SideConversation $conversation,
        string $body,
        ?string $authorId = null,
    ): SideConversationReply {
        $body = trim($body);
        if ('' === $body) {
            throw new \InvalidArgumentException('Body is required.');
        }

        $reply = (new SideConversationReply())
            ->setBody($body)
            ->setAuthorId($authorId);
        $conversation->addReply($reply);
        $this->em->persist($reply);
        $this->em->flush();

        return $reply;
    }

    public function close(SideConversation $conversation): void
    {
        $conversation->setStatus(SideConversation::STATUS_CLOSED);
        $this->em->flush();
    }

    /**
     * All side conversations for a ticket, newest first.
     *
     * @return SideConversation[]
     */
    public function forTicket(Ticket $ticket): array
    {
        return $this->em->getRepository(SideConversation::class)
            ->findBy(['ticket' => $ticket], ['createdAt' => 'DESC']);
    }
}
