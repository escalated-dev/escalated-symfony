<?php

declare(strict_types=1);

namespace Escalated\Symfony\Security;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Remembers, per session, which user has passed the two-factor challenge.
 * Stored as the user's key, so a session that passed for one user does not
 * count for another, and cleared on every login and logout
 * ({@see \Escalated\Symfony\EventSubscriber\TwoFactorSubscriber}).
 */
final class TwoFactorSession
{
    public const KEY = 'escalated.two_factor.passed';

    /** The page someone asked for before being sent to the challenge or to enrol. */
    public const INTENDED = 'escalated.two_factor.intended';

    public static function passed(?SessionInterface $session, int|string|null $userKey): bool
    {
        return null !== $session
            && null !== $userKey
            && $session->get(self::KEY) === (string) $userKey;
    }

    public static function markPassed(SessionInterface $session, int|string $userKey): void
    {
        $session->set(self::KEY, (string) $userKey);
    }

    public static function forget(?SessionInterface $session): void
    {
        $session?->remove(self::KEY);
    }

    public static function rememberIntended(SessionInterface $session, string $uri): void
    {
        $session->set(self::INTENDED, $uri);
    }

    /** The remembered page (a path on this site only), consumed. */
    public static function pullIntended(SessionInterface $session): ?string
    {
        $uri = $session->remove(self::INTENDED);

        return \is_string($uri) && str_starts_with($uri, '/') && !str_starts_with($uri, '//') && !str_contains($uri, '\\') ? $uri : null;
    }
}
