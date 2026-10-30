<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A permission slug (`ticket.view`, `settings.manage`, ...), as
 * escalated-laravel's Permission model. Seeded by
 * {@see \Escalated\Symfony\Security\PermissionSeeder}; roles hold them.
 */
#[ORM\Entity]
#[ORM\Table(name: 'escalated_permissions')]
class Permission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 191, unique: true)]
    private string $slug;

    // `group` is reserved on MySQL; the backticks make Doctrine quote it.
    #[ORM\Column(name: '`group`', type: Types::STRING, length: 255)]
    private string $group;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description;

    public function __construct(string $slug, string $name, string $group, ?string $description = null)
    {
        $this->slug = $slug;
        $this->name = $name;
        $this->group = $group;
        $this->description = $description;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getGroup(): string
    {
        return $this->group;
    }

    public function setGroup(string $group): self
    {
        $this->group = $group;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return array{id: int|null, name: string, slug: string, group: string, description: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'group' => $this->group,
            'description' => $this->description,
        ];
    }
}
