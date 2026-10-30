<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single-use SSO message ID (ADR 2026-10-29-configurable-access-features):
 * an AuthnRequest ID this install issued (`saml_request`), or a SAML assertion
 * ID (`saml_assertion`) or JWT `jti` (`jwt`) it has accepted. `expiresAt` is
 * Unix seconds; expired rows are pruned on write. Written and read through
 * {@see \Escalated\Symfony\Service\SsoMessageIds}; mapped so the schema tools
 * and `doctrine:schema:validate` know the table.
 */
#[ORM\Entity]
#[ORM\Table(name: 'escalated_sso_message_ids')]
#[ORM\UniqueConstraint(columns: ['kind', 'message_id'])]
#[ORM\Index(columns: ['expires_at'])]
class SsoMessageId
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 32)]
        private string $kind,
        #[ORM\Column(type: Types::STRING, length: 191)]
        private string $messageId,
        #[ORM\Column(type: Types::BIGINT)]
        private int|string $expiresAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getMessageId(): string
    {
        return $this->messageId;
    }

    public function getExpiresAt(): int
    {
        return (int) $this->expiresAt;
    }
}
