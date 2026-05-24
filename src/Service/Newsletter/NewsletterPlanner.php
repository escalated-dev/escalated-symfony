<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service\Newsletter;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Contact;
use Escalated\Symfony\Entity\Newsletter\Newsletter;
use Escalated\Symfony\Entity\Newsletter\NewsletterDelivery;

class NewsletterPlanner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContactSegmentResolver $segments,
        private readonly BounceSuppressionStore $bounces,
    ) {
    }

    public function plan(Newsletter $newsletter): void
    {
        $newsletter->setStatus('sending')->touch();
        $this->em->persist($newsletter);
        $this->em->flush();

        $list = $newsletter->getTargetListId()
            ? $this->em->find(\Escalated\Symfony\Entity\Newsletter\NewsletterList::class, $newsletter->getTargetListId())
            : null;
        if (!$list) {
            $newsletter->setSummaryTotal(0);
            $this->em->flush();

            return;
        }

        $contactIds = $this->segments->resolveSendable($list);
        if (!$contactIds) {
            $newsletter->setSummaryTotal(0);
            $this->em->flush();

            return;
        }

        $contacts = $this->em->createQueryBuilder()
            ->from(Contact::class, 'c')
            ->select('c.id', 'c.email')
            ->where('c.id IN (:ids)')
            ->setParameter('ids', $contactIds)
            ->getQuery()->getArrayResult();

        $emails = array_column($contacts, 'email');
        $sendable = array_flip(array_map('strtolower', $this->bounces->filterSendable($emails)));

        $batch = 0;
        foreach ($contacts as $c) {
            if (!isset($sendable[strtolower($c['email'])])) {
                continue;
            }
            $row = (new NewsletterDelivery())
                ->setNewsletterId($newsletter->getId())
                ->setContactId((int) $c['id'])
                ->setEmailAtSend($c['email'])
                ->setStatus('pending')
                ->setTrackingToken(bin2hex(random_bytes(20)))
                ->setAttemptCount(0)
                ->setIsTest(false);
            $this->em->persist($row);
            if (0 === ++$batch % 500) {
                $this->em->flush();
                $this->em->clear(NewsletterDelivery::class);
            }
        }
        $this->em->flush();

        $count = (int) $this->em->createQueryBuilder()
            ->from(NewsletterDelivery::class, 'd')
            ->select('COUNT(d.id)')
            ->where('d.newsletterId = :id')
            ->setParameter('id', $newsletter->getId())
            ->getQuery()->getSingleScalarResult();

        $newsletter->setSummaryTotal($count);
        $this->em->flush();
    }
}
