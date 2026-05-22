<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service\Newsletter;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Newsletter\Newsletter;
use Escalated\Symfony\Entity\Newsletter\NewsletterDelivery;

class NewsletterTracker
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BounceSuppressionStore $bounces,
    ) {}

    public function recordOpen(string $token): void
    {
        $d = $this->findByToken($token);
        if (!$d || in_array($d->getStatus(), ['bounced', 'complained', 'failed'], true)) return;
        if ($d->getOpenedAt() !== null) return;
        $d->setOpenedAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->incrementNewsletter($d->getNewsletterId(), 'incrementSummaryOpened');
    }

    public function recordClick(string $token, string $_url): void
    {
        $d = $this->findByToken($token);
        if (!$d || in_array($d->getStatus(), ['bounced', 'complained', 'failed'], true)) return;
        $firstClick = $d->getClicksCount() === 0;
        $d->setClicksCount($d->getClicksCount() + 1)->setLastClickedAt(new \DateTimeImmutable());
        if ($d->getOpenedAt() === null) {
            $d->setOpenedAt(new \DateTimeImmutable());
            $this->incrementNewsletter($d->getNewsletterId(), 'incrementSummaryOpened');
        }
        $this->em->flush();
        if ($firstClick) $this->incrementNewsletter($d->getNewsletterId(), 'incrementSummaryClicked');
    }

    public function recordBounce(string $token, string $type, ?string $reason = null): void
    {
        if ($type !== 'hard') return;
        $d = $this->findByToken($token);
        if (!$d || $d->getStatus() === 'bounced') return;
        $d->setStatus('bounced')->setBounceReason($reason);
        $this->em->flush();
        $this->incrementNewsletter($d->getNewsletterId(), 'incrementSummaryBounced');
        $this->bounces->markBounced($d->getEmailAtSend());
    }

    public function recordComplaint(string $token): void
    {
        $d = $this->findByToken($token);
        if (!$d || $d->getStatus() === 'complained') return;
        $d->setStatus('complained');
        $this->em->flush();
        $this->incrementNewsletter($d->getNewsletterId(), 'incrementSummaryComplained');
        $this->bounces->markComplained($d->getEmailAtSend());
    }

    private function findByToken(string $token): ?NewsletterDelivery
    {
        return $this->em->getRepository(NewsletterDelivery::class)->findOneBy(['trackingToken' => $token]);
    }

    private function incrementNewsletter(int $id, string $method): void
    {
        $n = $this->em->find(Newsletter::class, $id);
        if ($n) {
            $n->$method();
            $this->em->flush();
        }
    }
}
