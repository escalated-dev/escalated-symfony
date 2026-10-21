<?php

declare(strict_types=1);

namespace Escalated\Symfony\Security;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\GuestVerification;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;

/**
 * Proof that a guest controls a mailbox (escalated-laravel
 * docs/guest-access.md): an eight-digit code, sent only by email, that
 * expires after ten minutes, allows five guesses and is used once. It is bound
 * to the normalised email and a purpose (`ticket`, `chat` or `lookup`), and
 * stored only as keyed digests.
 *
 * Code delivery is budgeted per mailbox and client IP, so one client cannot
 * use up the owner's budget, and per mailbox across all clients, so a mailbox
 * cannot be flooded.
 */
class GuestEmailVerification
{
    public const CODE_TTL_SECONDS = 600;
    public const MAX_ATTEMPTS = 5;

    private const BUDGET_WINDOW_SECONDS = 3600;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CacheItemPoolInterface $cache,
        private readonly string $secret,
        private readonly int $codesPerClientPerHour = 3,
        private readonly int $codesPerMailboxPerHour = 10,
        private readonly ?string $fromAddress = null,
        private readonly ?MailerInterface $mailer = null,
    ) {
    }

    public static function normalize(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    /**
     * Emails a code and returns the verification id.
     *
     * @throws GuestVerificationException when the email or purpose is invalid
     * @throws GuestCodeBudgetException   when the mailbox is over its budget
     */
    public function challenge(string $email, string $purpose, string $clientIp): string
    {
        $email = self::normalize($email);
        if (mb_strlen($email) > 255 || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new GuestVerificationException('email', 'Enter a valid email address.');
        }
        if (!\in_array($purpose, GuestVerification::PURPOSES, true)) {
            throw new GuestVerificationException('purpose', 'Choose ticket, chat or lookup.');
        }
        if (null === $this->mailer) {
            throw new \LogicException('Guest email verification needs symfony/mailer configured (framework.mailer).');
        }

        $this->spendBudget($email, $clientIp);
        $this->purgeExpired();

        $id = Uuid::v4()->toRfc4122();
        $code = sprintf('%08d', random_int(0, 99_999_999));
        $verification = new GuestVerification(
            $id,
            $this->digest('email:'.$email),
            $purpose,
            $this->digest('code:'.$id.':'.$code),
            new \DateTimeImmutable(sprintf('+%d seconds', self::CODE_TTL_SECONDS)),
        );
        $this->em->persist($verification);
        $this->em->flush();

        $message = (new Email())
            ->to($email)
            ->subject('Your verification code')
            ->text(sprintf(
                "Your verification code is %s.\n\nIt expires in 10 minutes and can be used once. If you did not ask for it, ignore this email.\n",
                $code,
            ));
        if (null !== $this->fromAddress && '' !== $this->fromAddress) {
            $message->from($this->fromAddress);
        }

        try {
            $this->mailer->send($message);
        } catch (\Throwable $e) {
            $this->em->remove($verification);
            $this->em->flush();

            throw $e;
        }

        return $id;
    }

    /**
     * Runs $operation when the code is right, consuming the code in the same
     * transaction. A wrong guess still counts. When $operation throws, the
     * consumption is rolled back so the guest can retry.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     *
     * @throws GuestVerificationException when the code is wrong, expired, used or for another email or purpose
     */
    public function consume(mixed $id, mixed $code, string $email, string $purpose, callable $operation): mixed
    {
        if (!\is_string($id) || !\is_scalar($code) || '' === $id || mb_strlen($id) > 64) {
            throw self::invalidCode();
        }

        $result = $this->em->wrapInTransaction(function () use ($id, $code, $email, $purpose, $operation): array {
            $verification = $this->em->find(GuestVerification::class, $id, LockMode::PESSIMISTIC_WRITE);
            if (!$verification instanceof GuestVerification || !self::usable($verification)) {
                return ['valid' => false];
            }

            $verification->recordAttempt();
            $this->em->flush();
            if (!$this->matches($verification, (string) $code, $email, $purpose)) {
                return ['valid' => false];
            }

            $verification->markUsed();
            $this->em->flush();

            return ['valid' => true, 'value' => $operation()];
        });

        if (!$result['valid']) {
            throw self::invalidCode();
        }

        return $result['value'];
    }

    private static function invalidCode(): GuestVerificationException
    {
        return new GuestVerificationException('verification_code', 'This code is invalid, expired or already used. Request a new code.');
    }

    private static function usable(GuestVerification $verification): bool
    {
        return null === $verification->getUsedAt()
            && $verification->getExpiresAt() > new \DateTimeImmutable()
            && $verification->getAttempts() < self::MAX_ATTEMPTS;
    }

    private function matches(GuestVerification $verification, string $code, string $email, string $purpose): bool
    {
        return hash_equals($verification->getCodeHash(), $this->digest('code:'.$verification->getId().':'.$code))
            && hash_equals($verification->getEmailDigest(), $this->digest('email:'.self::normalize($email)))
            && $verification->getPurpose() === $purpose;
    }

    private function spendBudget(string $email, string $clientIp): void
    {
        $mailbox = 'escalated_guest_email_'.$this->digest('email:'.$email);
        $client = $mailbox.'_client_'.$this->digest('ip:'.$clientIp);
        $perClient = max(1, $this->codesPerClientPerHour);
        $perMailbox = max($perClient, $this->codesPerMailboxPerHour);
        $now = time();

        $buckets = [];
        foreach ([$client => $perClient, $mailbox => $perMailbox] as $key => $limit) {
            $item = $this->cache->getItem($key);
            $bucket = $item->isHit() ? $item->get() : null;
            if (!\is_array($bucket) || (int) ($bucket['reset'] ?? 0) <= $now) {
                $bucket = ['count' => 0, 'reset' => $now + self::BUDGET_WINDOW_SECONDS];
            }
            if ((int) $bucket['count'] >= $limit) {
                throw new GuestCodeBudgetException(max(1, (int) $bucket['reset'] - $now));
            }
            $buckets[] = [$item, $bucket];
        }

        foreach ($buckets as [$item, $bucket]) {
            $bucket['count'] = (int) $bucket['count'] + 1;
            $item->set($bucket);
            $item->expiresAfter(max(1, (int) $bucket['reset'] - $now));
            $this->cache->save($item);
        }
    }

    private function purgeExpired(): void
    {
        $this->em->createQueryBuilder()
            ->delete(GuestVerification::class, 'v')
            ->where('v.expiresAt < :cutoff')
            ->setParameter('cutoff', new \DateTimeImmutable('-1 day'))
            ->getQuery()
            ->execute();
    }

    private function digest(string $value): string
    {
        $key = hash_hkdf('sha256', $this->secret, 32, 'escalated guest email verification');

        return hash_hmac('sha256', 'escalated-guest-email:'.$value, $key);
    }
}
