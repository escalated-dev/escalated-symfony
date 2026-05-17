<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Escalated\Symfony\Repository\SkillRoutingTagRepository;

#[ORM\Entity(repositoryClass: SkillRoutingTagRepository::class)]
#[ORM\Table(name: 'escalated_skill_routing_tags')]
#[ORM\UniqueConstraint(name: 'UNIQ_skill_routing_tag', columns: ['skill_id', 'tag_id'])]
class SkillRoutingTag
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Skill::class, inversedBy: 'routingTags')]
    #[ORM\JoinColumn(name: 'skill_id', nullable: false, onDelete: 'CASCADE')]
    private ?Skill $skill = null;

    #[ORM\ManyToOne(targetEntity: Tag::class)]
    #[ORM\JoinColumn(name: 'tag_id', nullable: false, onDelete: 'CASCADE')]
    private ?Tag $tag = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSkill(): ?Skill
    {
        return $this->skill;
    }

    public function setSkill(?Skill $skill): self
    {
        $this->skill = $skill;

        return $this;
    }

    public function getTag(): ?Tag
    {
        return $this->tag;
    }

    public function setTag(?Tag $tag): self
    {
        $this->tag = $tag;

        return $this;
    }
}
