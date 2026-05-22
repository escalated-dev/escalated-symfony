<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service\Newsletter;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Contact;
use Escalated\Symfony\Entity\Newsletter\Newsletter;
use Escalated\Symfony\Entity\Newsletter\NewsletterDelivery;
use Escalated\Symfony\Entity\Newsletter\NewsletterTemplate;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class NewsletterDispatcher
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
        private readonly NewsletterRenderer $renderer,
        private readonly bool $enabled = false,
        private readonly int $batchSize = 50,
        private readonly int $claimTimeoutMinutes = 10,
        private readonly float $autoPauseBounceRate = 0.05,
        private readonly int $autoPauseThreshold = 100,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function dispatchBatch(): void
    {
        if (!$this->enabled) return;
        $this->reclaimStuckRows();

        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            $ids = array_map('intval', $conn->fetchFirstColumn(
                'SELECT id FROM escalated_newsletter_deliveries WHERE status = :s ORDER BY id ASC LIMIT ' . $this->batchSize,
                ['s' => 'pending'],
            ));
            if ($ids) {
                $conn->executeStatement(
                    'UPDATE escalated_newsletter_deliveries SET status = :ns, claimed_at = :ts WHERE id IN (?)',
                    ['ns' => 'queued', 'ts' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), $ids],
                    [\PDO::PARAM_STR, \PDO::PARAM_STR, \Doctrine\DBAL\ArrayParameterType::INTEGER],
                );
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }

        foreach ($ids as $id) {
            $d = $this->em->find(NewsletterDelivery::class, $id);
            if ($d) $this->dispatchOne($d);
        }

        $this->finalizeCompletedNewsletters();
        $this->checkAutoPause();
    }

    private function dispatchOne(NewsletterDelivery $delivery): void
    {
        try {
            $newsletter = $this->em->find(Newsletter::class, $delivery->getNewsletterId());
            $template = $newsletter && $newsletter->getTemplateId()
                ? $this->em->find(NewsletterTemplate::class, $newsletter->getTemplateId())
                : null;
            $templateArr = $template ? ['body_markdown' => $template->getBodyMarkdown(), 'theme' => $template->getTheme()] : null;
            $contact = $this->em->find(Contact::class, $delivery->getContactId());
            if (!$newsletter || !$contact) return;

            $html = $this->renderer->render($delivery, $newsletter, $contact, $templateArr);
            $unsub = $this->renderer->unsubscribeUrl($delivery);
            $host = parse_url($_ENV['APP_URL'] ?? 'http://localhost', PHP_URL_HOST) ?: 'localhost';

            $email = (new Email())
                ->from($newsletter->getFromName() ? "{$newsletter->getFromName()} <{$newsletter->getFromEmail()}>" : $newsletter->getFromEmail())
                ->to($delivery->getEmailAtSend())
                ->subject($newsletter->getSubject())
                ->html($html);
            if ($newsletter->getReplyTo()) $email->replyTo($newsletter->getReplyTo());
            $email->getHeaders()->addTextHeader('List-Unsubscribe', "<{$unsub}>");
            $email->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            $email->getHeaders()->addTextHeader('X-Escalated-Newsletter-Id', (string) $newsletter->getId());
            $email->getHeaders()->addIdHeader('Message-ID', "n-{$newsletter->getId()}-{$delivery->getTrackingToken()}@{$host}");

            $this->mailer->send($email);

            $delivery->setStatus('sent')->setSentAt(new \DateTimeImmutable())->setClaimedAt(null);
            $newsletter->incrementSummarySent();
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->warning("Newsletter delivery {$delivery->getId()} failed: " . $e->getMessage());
            $attempts = $delivery->getAttemptCount() + 1;
            if ($attempts >= 3) {
                $delivery->setStatus('failed')->setFailureReason($e->getMessage())->setAttemptCount($attempts)->setClaimedAt(null);
            } else {
                $delivery->setStatus('pending')->setAttemptCount($attempts)->setClaimedAt(null);
            }
            $this->em->flush();
        }
    }

    private function reclaimStuckRows(): void
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$this->claimTimeoutMinutes} minutes")->format('Y-m-d H:i:s');
        $this->em->getConnection()->executeStatement(
            'UPDATE escalated_newsletter_deliveries SET status = :ns, claimed_at = NULL WHERE status = :s AND claimed_at < :cutoff',
            ['ns' => 'pending', 's' => 'queued', 'cutoff' => $cutoff],
        );
    }

    private function finalizeCompletedNewsletters(): void
    {
        $sending = $this->em->getRepository(Newsletter::class)->findBy(['status' => 'sending']);
        foreach ($sending as $n) {
            $remaining = (int) $this->em->createQueryBuilder()
                ->from(NewsletterDelivery::class, 'd')
                ->select('COUNT(d.id)')
                ->where('d.newsletterId = :id AND d.status IN (:s)')
                ->setParameter('id', $n->getId())
                ->setParameter('s', ['pending', 'queued'])
                ->getQuery()->getSingleScalarResult();
            if ($remaining === 0) {
                $n->setStatus('sent');
                if (!$n->getSentAt()) $n->setSentAt(new \DateTimeImmutable());
                $this->em->flush();
            }
        }
    }

    private function checkAutoPause(): void
    {
        foreach ($this->em->getRepository(Newsletter::class)->findBy(['status' => 'sending']) as $n) {
            $total = (int) $this->em->createQueryBuilder()
                ->from(NewsletterDelivery::class, 'd')->select('COUNT(d.id)')
                ->where('d.newsletterId = :id AND d.status IN (:s)')
                ->setParameter('id', $n->getId())
                ->setParameter('s', ['sent', 'bounced', 'complained', 'failed'])
                ->getQuery()->getSingleScalarResult();
            if ($total < $this->autoPauseThreshold) continue;
            $bounced = (int) $this->em->createQueryBuilder()
                ->from(NewsletterDelivery::class, 'd')->select('COUNT(d.id)')
                ->where('d.newsletterId = :id AND d.status = :s')
                ->setParameter('id', $n->getId())->setParameter('s', 'bounced')
                ->getQuery()->getSingleScalarResult();
            if ($total > 0 && $bounced / $total >= $this->autoPauseBounceRate) {
                $n->setStatus('paused');
                $this->em->flush();
                $this->logger->warning("Newsletter {$n->getId()} auto-paused: {$bounced}/{$total} bounced");
            }
        }
    }
}
