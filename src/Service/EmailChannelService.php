<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\EmailChannel;

class EmailChannelService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function create(string $emailAddress, ?string $displayName = null, ?int $departmentId = null): EmailChannel
    {
        $channel = new EmailChannel();
        $channel->setEmailAddress($emailAddress);
        $channel->setDisplayName($displayName);

        if ($departmentId) {
            $department = $this->em->getRepository(\Escalated\Symfony\Entity\Department::class)->find($departmentId);
            $channel->setDepartment($department);
        }

        $this->em->persist($channel);
        $this->em->flush();

        return $channel;
    }

    public function findByAddress(string $emailAddress): ?EmailChannel
    {
        return $this->em->getRepository(EmailChannel::class)->findOneBy(['emailAddress' => $emailAddress]);
    }

    public function findByDepartment(int $departmentId): array
    {
        return $this->em->getRepository(EmailChannel::class)->findBy(['department' => $departmentId]);
    }

    public function getDefault(): ?EmailChannel
    {
        return $this->em->getRepository(EmailChannel::class)->findOneBy(['isDefault' => true, 'isActive' => true]);
    }

    public function setDefault(EmailChannel $channel): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE escalated_email_channels SET is_default = 0'
        );
        $channel->setIsDefault(true);
        $this->em->flush();
    }

    public function verifyDkim(EmailChannel $channel): array
    {
        $domain = substr($channel->getEmailAddress(), strpos($channel->getEmailAddress(), '@') + 1);
        $selector = $channel->getDkimSelector() ?? 'escalated';
        $dkimHost = sprintf('%s._domainkey.%s', $selector, $domain);

        $records = @dns_get_record($dkimHost, DNS_TXT);
        $verified = false;

        if ($records) {
            foreach ($records as $record) {
                $txt = $record['txt'] ?? '';
                if (str_contains($txt, 'v=DKIM1') && $channel->getDkimPublicKey() && str_contains($txt, $channel->getDkimPublicKey())) {
                    $verified = true;
                    break;
                }
            }
        }

        $channel->setDkimStatus($verified ? 'verified' : 'failed');
        $channel->setIsVerified($verified);
        $this->em->flush();

        return [
            'domain' => $domain,
            'selector' => $selector,
            'dns_host' => $dkimHost,
            'verified' => $verified,
        ];
    }

    public function delete(EmailChannel $channel): void
    {
        $this->em->remove($channel);
        $this->em->flush();
    }
}
