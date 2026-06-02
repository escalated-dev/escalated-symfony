<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Security;

use Escalated\Symfony\Security\NewsletterPermissions;
use Escalated\Symfony\Security\NewsletterPermissionVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class NewsletterPermissionVoterTest extends TestCase
{
    public function testSeedsExposeRequiredSlugs(): void
    {
        $seeds = NewsletterPermissions::seeds();

        $this->assertArrayHasKey('newsletters.manage', $seeds);
        $this->assertArrayHasKey('newsletters.send', $seeds);
        $this->assertSame('Newsletters', $seeds['newsletters.manage']['group']);
    }

    public function testAdminRoleReceivesNewsletterPermissions(): void
    {
        $voter = new NewsletterPermissionVoter();
        $token = $this->tokenWithRoles(['ROLE_ESCALATED_ADMIN']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, null, [NewsletterPermissions::MANAGE]));
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, null, [NewsletterPermissions::SEND]));
    }

    public function testPlainUserIsDenied(): void
    {
        $voter = new NewsletterPermissionVoter();

        $this->assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->tokenWithRoles(['ROLE_USER']), null, [NewsletterPermissions::MANAGE]));
    }

    /**
     * @param array<int, string> $roles
     */
    private function tokenWithRoles(array $roles): TokenInterface
    {
        $user = new class($roles) implements UserInterface {
            public function __construct(private readonly array $roles)
            {
            }

            public function getRoles(): array
            {
                return $this->roles;
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return 'user@example.test';
            }
        };

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }
}
