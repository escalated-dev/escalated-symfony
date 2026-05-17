<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Dto\SkillWriteInput;
use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\AgentSkill;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\Skill;
use Escalated\Symfony\Entity\SkillRoutingDepartment;
use Escalated\Symfony\Entity\SkillRoutingTag;
use Escalated\Symfony\Entity\Tag;
use Escalated\Symfony\Repository\SkillRepository;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\String\Slugger\SluggerInterface;

class SkillService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SkillRepository $skillRepository,
        private readonly SluggerInterface $slugger,
        private readonly string $userClass,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForAdmin(): array
    {
        /** @var Skill[] $skills */
        $skills = $this->skillRepository->findBy([], ['name' => 'ASC']);
        $ids = array_values(array_filter(array_map(fn (Skill $s) => $s->getId(), $skills)));
        $counts = $this->skillRepository->aggregateCountsBySkillId($ids);

        $rows = [];
        foreach ($skills as $skill) {
            $id = $skill->getId();
            if (null === $id) {
                continue;
            }
            $c = $counts[$id] ?? ['agents' => 0, 'routing_tags' => 0, 'routing_departments' => 0];
            $rows[] = [
                'id' => $id,
                'name' => $skill->getName(),
                'agents_count' => $c['agents'],
                'routing_tags_count' => $c['routing_tags'],
                'routing_departments_count' => $c['routing_departments'],
                'updated_at' => $this->formatIsoUtc($skill->getUpdatedAt()),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function findForEdit(int $id): array
    {
        $skill = $this->skillRepository->find($id);
        if (null === $skill) {
            throw new \InvalidArgumentException('Skill not found.');
        }

        $routingTagIds = [];
        foreach ($skill->getRoutingTags() as $rt) {
            $tid = $rt->getTag()?->getId();
            if (null !== $tid) {
                $routingTagIds[] = $tid;
            }
        }
        $routingDepartmentIds = [];
        foreach ($skill->getRoutingDepartments() as $rd) {
            $did = $rd->getDepartment()?->getId();
            if (null !== $did) {
                $routingDepartmentIds[] = $did;
            }
        }
        $agents = [];
        foreach ($skill->getAgentSkills() as $as) {
            $agents[] = [
                'user_id' => $as->getUserId(),
                'proficiency' => $as->getProficiency(),
            ];
        }

        return [
            'id' => $skill->getId(),
            'name' => $skill->getName(),
            'routing_tag_ids' => $routingTagIds,
            'routing_department_ids' => $routingDepartmentIds,
            'agents' => $agents,
        ];
    }

    /**
     * @return array{
     *     available_agents: list<array{id: int, name: string, email: string}>,
     *     available_tags: list<array{id: int, name: string}>,
     *     available_departments: list<array{id: int, name: string}>
     * }
     */
    public function getFormContext(): array
    {
        return [
            'available_agents' => $this->loadAvailableAgents(),
            'available_tags' => $this->loadAvailableTags(),
            'available_departments' => $this->loadAvailableDepartments(),
        ];
    }

    public function create(SkillWriteInput $input): Skill
    {
        if ($this->skillRepository->existsOtherWithName($input->name, null)) {
            throw new UnprocessableEntityHttpException('A skill with this name already exists.');
        }

        $slug = $this->uniqueSlugForName($input->name, null);

        return $this->em->wrapInTransaction(function () use ($input, $slug) {
            $this->assertForeignKeysExist($input);

            $skill = new Skill();
            $skill->setName($input->name);
            $skill->setSlug($slug);
            $this->em->persist($skill);
            $this->syncRelations($skill, $input);
            $this->em->flush();

            return $skill;
        });
    }

    public function update(int $id, SkillWriteInput $input): Skill
    {
        $skill = $this->skillRepository->find($id);
        if (null === $skill) {
            throw new \InvalidArgumentException('Skill not found.');
        }

        if ($this->skillRepository->existsOtherWithName($input->name, $id)) {
            throw new UnprocessableEntityHttpException('A skill with this name already exists.');
        }

        $slug = $skill->getSlug();
        if ($skill->getName() !== $input->name) {
            $slug = $this->uniqueSlugForName($input->name, $id);
        }

        return $this->em->wrapInTransaction(function () use ($skill, $input, $slug) {
            $this->assertForeignKeysExist($input);

            $skill->setName($input->name);
            $skill->setSlug($slug);
            $this->clearRelations($skill);
            $this->syncRelations($skill, $input);
            $this->em->flush();

            return $skill;
        });
    }

    public function delete(int $id): void
    {
        $skill = $this->skillRepository->find($id);
        if (null === $skill) {
            throw new \InvalidArgumentException('Skill not found.');
        }

        $this->em->wrapInTransaction(function () use ($skill) {
            $this->em->remove($skill);
            $this->em->flush();
        });
    }

    private function uniqueSlugForName(string $name, ?int $excludeId): string
    {
        $base = strtolower((string) $this->slugger->slug($name));
        $slug = $base;
        $i = 2;
        while ($this->skillRepository->existsOtherWithSlug($slug, $excludeId)) {
            $slug = $base.'-'.$i;
            ++$i;
        }

        return $slug;
    }

    private function clearRelations(Skill $skill): void
    {
        foreach ($skill->getRoutingTags()->toArray() as $row) {
            $skill->removeRoutingTag($row);
        }
        foreach ($skill->getRoutingDepartments()->toArray() as $row) {
            $skill->removeRoutingDepartment($row);
        }
        foreach ($skill->getAgentSkills()->toArray() as $row) {
            $skill->removeAgentSkill($row);
        }
    }

    private function syncRelations(Skill $skill, SkillWriteInput $input): void
    {
        foreach ($input->routingTagIds as $tagId) {
            $row = new SkillRoutingTag();
            $row->setTag($this->em->getReference(Tag::class, $tagId));
            $skill->addRoutingTag($row);
        }

        foreach ($input->routingDepartmentIds as $deptId) {
            $row = new SkillRoutingDepartment();
            $row->setDepartment($this->em->getReference(Department::class, $deptId));
            $skill->addRoutingDepartment($row);
        }

        foreach ($input->agents as $agent) {
            $as = new AgentSkill();
            $as->setUserId($agent['user_id']);
            $as->setProficiency($agent['proficiency']);
            $skill->addAgentSkill($as);
        }
    }

    private function assertForeignKeysExist(SkillWriteInput $input): void
    {
        foreach ($input->routingTagIds as $tagId) {
            if (null === $this->em->find(Tag::class, $tagId)) {
                throw new UnprocessableEntityHttpException(sprintf('Unknown tag id %d.', $tagId));
            }
        }
        foreach ($input->routingDepartmentIds as $deptId) {
            if (null === $this->em->find(Department::class, $deptId)) {
                throw new UnprocessableEntityHttpException(sprintf('Unknown department id %d.', $deptId));
            }
        }

        $agentUserIds = $this->agentUserIds();
        foreach ($input->agents as $agent) {
            if (!\in_array($agent['user_id'], $agentUserIds, true)) {
                throw new UnprocessableEntityHttpException(sprintf('User %d is not a registered agent.', $agent['user_id']));
            }
        }
    }

    /**
     * @return list<int>
     */
    private function agentUserIds(): array
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('ap.userId')
            ->from(AgentProfile::class, 'ap')
            ->orderBy('ap.userId', 'ASC');

        return array_map('intval', $qb->getQuery()->getSingleColumnResult());
    }

    /**
     * @return list<array{id: int, name: string, email: string}>
     */
    private function loadAvailableAgents(): array
    {
        $ids = $this->agentUserIds();
        if ([] === $ids) {
            return [];
        }

        $repo = $this->em->getRepository($this->userClass);
        $users = $repo->findBy(['id' => $ids], ['id' => 'ASC']);

        $byId = [];
        foreach ($users as $user) {
            $id = $this->readUserId($user);
            if (null === $id) {
                continue;
            }
            $byId[$id] = $user;
        }

        $out = [];
        foreach ($ids as $id) {
            $user = $byId[$id] ?? null;
            if (null === $user) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'name' => $this->readUserName($user),
                'email' => $this->readUserEmail($user),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function loadAvailableTags(): array
    {
        $tags = $this->em->getRepository(Tag::class)->findBy([], ['name' => 'ASC']);
        $out = [];
        foreach ($tags as $tag) {
            $id = $tag->getId();
            if (null === $id) {
                continue;
            }
            $out[] = ['id' => $id, 'name' => $tag->getName()];
        }

        return $out;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function loadAvailableDepartments(): array
    {
        $depts = $this->em->getRepository(Department::class)->findBy([], ['name' => 'ASC']);
        $out = [];
        foreach ($depts as $d) {
            $id = $d->getId();
            if (null === $id) {
                continue;
            }
            $out[] = ['id' => $id, 'name' => $d->getName()];
        }

        return $out;
    }

    private function formatIsoUtc(\DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private function readUserId(object $user): ?int
    {
        if (method_exists($user, 'getId')) {
            $id = $user->getId();

            return null === $id ? null : (int) $id;
        }

        return null;
    }

    private function readUserName(object $user): string
    {
        if (method_exists($user, 'getName')) {
            return (string) $user->getName();
        }

        return '';
    }

    private function readUserEmail(object $user): string
    {
        if (method_exists($user, 'getEmail')) {
            return (string) $user->getEmail();
        }
        if (method_exists($user, 'getUserIdentifier')) {
            return (string) $user->getUserIdentifier();
        }

        return '';
    }
}
