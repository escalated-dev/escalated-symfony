<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Macro;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\Tag;
use Escalated\Symfony\Entity\Ticket;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * MacroService — agent-applied, manual one-click action bundles.
 *
 * Apply a macro to a ticket: looks up by id, runs each action in order
 * against the ticket. Per-action try/catch so one bad action does not
 * abort the rest.
 *
 * Distinct from AutomationRunner (admin time-based) and WorkflowEngine
 * (admin event-driven). See escalated-developer-context/domain-model/
 * workflows-automations-macros.md.
 */
class MacroService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Macros visible to a given agent: shared macros + macros they created.
     *
     * @return Macro[]
     */
    public function listForAgent(int $agentId): array
    {
        return $this->em->createQueryBuilder()
            ->select('m')
            ->from(Macro::class, 'm')
            ->where('m.isShared = :shared OR m.createdBy = :agent')
            ->setParameter('shared', true)
            ->setParameter('agent', $agentId)
            ->orderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findById(int $id): ?Macro
    {
        return $this->em->getRepository(Macro::class)->find($id);
    }

    public function create(array $data): Macro
    {
        $macro = new Macro();
        $macro->setName($data['name'] ?? '');
        $macro->setDescription($data['description'] ?? null);
        $macro->setActions($data['actions'] ?? []);
        $macro->setIsShared($data['isShared'] ?? true);
        $macro->setCreatedBy($data['createdBy'] ?? null);

        $this->em->persist($macro);
        $this->em->flush();

        return $macro;
    }

    public function update(Macro $macro, array $data): Macro
    {
        if (isset($data['name'])) {
            $macro->setName($data['name']);
        }
        if (array_key_exists('description', $data)) {
            $macro->setDescription($data['description']);
        }
        if (isset($data['actions'])) {
            $macro->setActions($data['actions']);
        }
        if (isset($data['isShared'])) {
            $macro->setIsShared($data['isShared']);
        }

        $this->em->flush();

        return $macro;
    }

    public function delete(Macro $macro): void
    {
        $this->em->remove($macro);
        $this->em->flush();
    }

    /**
     * Apply a macro to a ticket. Each action runs in order; per-action
     * failure is caught and logged so a bad action does not abort the
     * rest of the bundle.
     */
    public function apply(Macro $macro, Ticket $ticket, int $agentId): Ticket
    {
        foreach ($macro->getActions() as $action) {
            $type = $action['type'] ?? '';
            $value = $action['value'] ?? null;

            try {
                switch ($type) {
                    case 'change_status':
                    case 'set_status':
                        $ticket->setStatus((string) $value);
                        $this->em->flush();
                        break;
                    case 'change_priority':
                    case 'set_priority':
                        $ticket->setPriority((string) $value);
                        $this->em->flush();
                        break;
                    case 'assign':
                        $ticket->setAssignedTo((int) $value);
                        $this->em->flush();
                        break;
                    case 'add_tag':
                        $tag = $this->em->getRepository(Tag::class)
                            ->findOneBy(['name' => (string) $value]);
                        if (null !== $tag && !$ticket->getTags()->contains($tag)) {
                            $ticket->getTags()->add($tag);
                            $this->em->flush();
                        }
                        break;

                    case 'add_reply':
                        $this->createReply($ticket, $agentId, (string) $value, isInternal: false);
                        break;
                    case 'add_note':
                        $this->createReply($ticket, $agentId, (string) $value, isInternal: true);
                        break;
                    case 'insert_canned_reply':
                        // Frontend resolves the canned response body before
                        // POSTing; stored value is the resolved text.
                        $this->createReply($ticket, $agentId, (string) $value, isInternal: false);
                        break;
                        // Unknown action types skipped silently.
                }
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf(
                    'Macro #%d action %s on ticket #%d (agent %d) failed: %s',
                    $macro->getId() ?? 0,
                    $type,
                    $ticket->getId() ?? 0,
                    $agentId,
                    $e->getMessage()
                ));
            }
        }

        return $ticket;
    }

    private function createReply(Ticket $ticket, int $authorId, string $body, bool $isInternal): void
    {
        $reply = new Reply();
        $reply->setTicket($ticket);
        $reply->setAuthorId($authorId);
        $reply->setBody($body);
        $reply->setIsInternalNote($isInternal);
        $this->em->persist($reply);
        $this->em->flush();
    }
}
