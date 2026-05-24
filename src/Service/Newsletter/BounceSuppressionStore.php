<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service\Newsletter;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\EscalatedSetting;

class BounceSuppressionStore
{
    private const KEY = 'newsletter.suppressed_emails';

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function markBounced(string $email): void
    {
        $this->mark($email);
    }

    public function markComplained(string $email): void
    {
        $this->mark($email);
    }

    public function isBounced(string $email): bool
    {
        return in_array(strtolower($email), $this->load(), true);
    }

    /** @param array<string> $emails @return array<string> */
    public function filterSendable(array $emails): array
    {
        $suppressed = array_flip($this->load());

        return array_values(array_filter($emails, fn ($e) => !isset($suppressed[strtolower($e)])));
    }

    private function mark(string $email): void
    {
        $lower = strtolower($email);
        $list = $this->load();
        if (in_array($lower, $list, true)) {
            return;
        }
        $list[] = $lower;

        $repo = $this->em->getRepository(EscalatedSetting::class);
        $row = $repo->findOneBy(['key' => self::KEY]);
        if (!$row) {
            $row = (new EscalatedSetting())->setKey(self::KEY);
        }
        $row->setValue(json_encode($list, JSON_UNESCAPED_UNICODE));
        $this->em->persist($row);
        $this->em->flush();
    }

    /** @return array<string> */
    private function load(): array
    {
        $row = $this->em->getRepository(EscalatedSetting::class)->findOneBy(['key' => self::KEY]);
        if (!$row || null === $row->getValue()) {
            return [];
        }
        $parsed = json_decode($row->getValue(), true);

        return is_array($parsed) ? array_map('strtolower', array_map('strval', $parsed)) : [];
    }
}
