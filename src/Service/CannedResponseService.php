<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\CannedResponse;

/**
 * CannedResponseService — server-side library of reusable canned replies.
 *
 * Owns the shared/own visibility query used by the agent-facing list and
 * the write path used by the admin CRUD surface.
 *
 * Mirrors the CannedResponse model scopes (shared / forAgent) in
 * escalated-laravel.
 */
class CannedResponseService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * All responses, newest first — the admin management surface.
     *
     * @return CannedResponse[]
     */
    public function listForAdmin(): array
    {
        return $this->em->getRepository(CannedResponse::class)
            ->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * Responses visible to a given agent: shared responses + responses
     * they created. Mirrors CannedResponse::scopeForAgent in Laravel.
     *
     * @return CannedResponse[]
     */
    public function listForAgent(int $agentId): array
    {
        return $this->em->createQueryBuilder()
            ->select('c')
            ->from(CannedResponse::class, 'c')
            ->where('c.isShared = :shared OR c.createdBy = :agent')
            ->setParameter('shared', true)
            ->setParameter('agent', $agentId)
            ->orderBy('c.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findById(int $id): ?CannedResponse
    {
        return $this->em->getRepository(CannedResponse::class)->find($id);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): CannedResponse
    {
        $response = new CannedResponse();
        $response->setTitle((string) ($data['title'] ?? ''));
        $response->setBody((string) ($data['body'] ?? ''));
        $response->setCategory(isset($data['category']) ? (string) $data['category'] : null);
        $response->setIsShared($data['isShared'] ?? true);
        $response->setCreatedBy($data['createdBy'] ?? null);

        $this->em->persist($response);
        $this->em->flush();

        return $response;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(CannedResponse $response, array $data): CannedResponse
    {
        if (isset($data['title'])) {
            $response->setTitle((string) $data['title']);
        }
        if (isset($data['body'])) {
            $response->setBody((string) $data['body']);
        }
        if (array_key_exists('category', $data)) {
            $response->setCategory(null === $data['category'] ? null : (string) $data['category']);
        }
        if (isset($data['isShared'])) {
            $response->setIsShared((bool) $data['isShared']);
        }

        $this->em->flush();

        return $response;
    }

    public function delete(CannedResponse $response): void
    {
        $this->em->remove($response);
        $this->em->flush();
    }
}
