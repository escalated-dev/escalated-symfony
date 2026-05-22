<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity\Newsletter;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'escalated_newsletter_templates')]
class NewsletterTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 64)]
    private string $theme = 'default';

    #[ORM\Column(name: 'subject_template', length: 998, nullable: true)]
    private ?string $subjectTemplate = null;

    #[ORM\Column(name: 'body_markdown', type: Types::TEXT)]
    private string $bodyMarkdown = '';

    #[ORM\Column(name: 'merge_fields_schema', type: Types::JSON, nullable: true)]
    private ?array $mergeFieldsSchema = null;

    #[ORM\Column(name: 'created_by', type: Types::BIGINT, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $v): self { $this->name = $v; return $this; }
    public function getTheme(): string { return $this->theme; }
    public function setTheme(string $v): self { $this->theme = $v; return $this; }
    public function getSubjectTemplate(): ?string { return $this->subjectTemplate; }
    public function setSubjectTemplate(?string $v): self { $this->subjectTemplate = $v; return $this; }
    public function getBodyMarkdown(): string { return $this->bodyMarkdown; }
    public function setBodyMarkdown(string $v): self { $this->bodyMarkdown = $v; return $this; }
    public function getMergeFieldsSchema(): ?array { return $this->mergeFieldsSchema; }
    public function setMergeFieldsSchema(?array $v): self { $this->mergeFieldsSchema = $v; return $this; }
    public function touch(): self { $this->updatedAt = new \DateTimeImmutable(); return $this; }
}
