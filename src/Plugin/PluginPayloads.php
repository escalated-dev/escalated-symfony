<?php

declare(strict_types=1);

namespace Escalated\Symfony\Plugin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\ChatSession;
use Escalated\Symfony\Entity\Contact;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\Tag;
use Escalated\Symfony\Entity\Ticket;

/**
 * The snake_case objects plugins are given: in event payloads and from the
 * `ctx.*` reads. Never a guest token, access hash or visitor chat token.
 */
final class PluginPayloads
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function ticket(Ticket $ticket): array
    {
        return [
            'id' => $ticket->getId(),
            'reference' => $ticket->getReference(),
            'subject' => $ticket->getSubject(),
            'title' => $ticket->getSubject(),
            'description' => $ticket->getDescription(),
            'status' => $ticket->getStatus(),
            'priority' => $ticket->getPriority(),
            'channel' => $ticket->getChannel(),
            'ticket_type' => $ticket->getTicketType(),
            'department_id' => $ticket->getDepartment()?->getId(),
            'assigned_to' => $ticket->getAssignedTo(),
            'requester_id' => $ticket->getRequesterId(),
            'contact_id' => $ticket->getContact()?->getId(),
            'metadata' => $ticket->getMetadata() ?? new \stdClass(),
            'tags' => array_values(array_map(fn (Tag $tag): array => $this->tag($tag), $ticket->getTags()->toArray())),
            'created_at' => self::date($ticket->getCreatedAt()),
            'updated_at' => self::date($ticket->getUpdatedAt()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reply(Reply $reply): array
    {
        return [
            'id' => $reply->getId(),
            'ticket_id' => $reply->getTicket()->getId(),
            'body' => $reply->getBody(),
            'type' => $reply->getType(),
            'is_internal' => $reply->isInternalNote(),
            'user_id' => $reply->getAuthorId(),
            'created_at' => self::date($reply->getCreatedAt()),
            'updated_at' => self::date($reply->getUpdatedAt()),
        ];
    }

    /**
     * @return array{id: ?int, name: string, slug: string}
     */
    public function tag(Tag $tag): array
    {
        return ['id' => $tag->getId(), 'name' => $tag->getName(), 'slug' => $tag->getSlug()];
    }

    /**
     * @return array<string, mixed>
     */
    public function tagWithColor(Tag $tag): array
    {
        return $this->tag($tag) + ['color' => $tag->getColor()];
    }

    /**
     * @return array<string, mixed>
     */
    public function department(Department $department): array
    {
        return [
            'id' => $department->getId(),
            'name' => $department->getName(),
            'slug' => $department->getSlug(),
            'description' => $department->getDescription(),
            'is_active' => $department->isActive(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function contact(Contact $contact): array
    {
        return [
            'id' => $contact->getId(),
            'name' => $contact->getName(),
            'email' => $contact->getEmail(),
            'user_id' => $contact->getUserId(),
            'created_at' => self::date($contact->getCreatedAt()),
            'updated_at' => self::date($contact->getUpdatedAt()),
        ];
    }

    /**
     * `{session}` for a chat event: the session's own id, never the visitor's
     * token or email.
     *
     * @param array<string, mixed> $extras
     *
     * @return array{session: array<string, mixed>}
     */
    public function chat(Ticket $ticket, mixed $sessionId, array $extras): array
    {
        $session = is_numeric($sessionId) ? $this->em->find(ChatSession::class, (int) $sessionId) : null;

        return ['session' => [
            'session_id' => $session?->getId() ?? (is_numeric($sessionId) ? (int) $sessionId : null),
            'ticket_id' => $ticket->getId(),
            'status' => $session?->getStatus(),
            'agent_id' => $session?->getAgentId() ?? $ticket->getAssignedTo(),
            'department_id' => $ticket->getDepartment()?->getId(),
        ] + $extras];
    }

    public static function date(?\DateTimeInterface $date): ?string
    {
        return $date?->format(\DateTimeInterface::ATOM);
    }
}
