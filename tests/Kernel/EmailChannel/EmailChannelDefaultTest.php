<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\EmailChannel;

use Escalated\Symfony\Entity\EmailChannel;
use Escalated\Symfony\Service\EmailChannelService;
use Escalated\Symfony\Tests\Kernel\EscalatedKernelTestCase;

/**
 * EmailChannelService::setDefault() cleared the previous default with
 * `SET is_default = 0`, which PostgreSQL rejects for a boolean column, so
 * the default channel could not be changed there.
 */
final class EmailChannelDefaultTest extends EscalatedKernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        self::createSchema();
    }

    public function testSettingADefaultLeavesExactlyOneDefault(): void
    {
        $em = self::entityManager();
        $first = (new EmailChannel())->setEmailAddress('help@example.com')->setIsDefault(true);
        $second = (new EmailChannel())->setEmailAddress('billing@example.com');
        $em->persist($first);
        $em->persist($second);
        $em->flush();

        (new EmailChannelService($em))->setDefault($second);

        $em->clear();
        $defaults = $em->getRepository(EmailChannel::class)->findBy(['isDefault' => true]);
        self::assertCount(1, $defaults);
        self::assertSame('billing@example.com', $defaults[0]->getEmailAddress());
    }
}
