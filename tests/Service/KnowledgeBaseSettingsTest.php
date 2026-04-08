<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Service;

use Escalated\Symfony\Service\KnowledgeBaseSettings;
use PHPUnit\Framework\TestCase;

class KnowledgeBaseSettingsTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $settings = new KnowledgeBaseSettings();

        $this->assertTrue($settings->isEnabled());
        $this->assertTrue($settings->isPublicAccess());
        $this->assertTrue($settings->isFeedbackEnabled());
    }

    public function testCustomValues(): void
    {
        $settings = new KnowledgeBaseSettings(
            enabled: false,
            publicAccess: false,
            feedbackEnabled: false,
        );

        $this->assertFalse($settings->isEnabled());
        $this->assertFalse($settings->isPublicAccess());
        $this->assertFalse($settings->isFeedbackEnabled());
    }

    public function testFluentSetters(): void
    {
        $settings = new KnowledgeBaseSettings();
        $result = $settings->setEnabled(false)
            ->setPublicAccess(false)
            ->setFeedbackEnabled(false);

        $this->assertSame($settings, $result);
        $this->assertFalse($settings->isEnabled());
        $this->assertFalse($settings->isPublicAccess());
        $this->assertFalse($settings->isFeedbackEnabled());
    }

    public function testIsAccessiblePublicly(): void
    {
        $settings = new KnowledgeBaseSettings();
        $this->assertTrue($settings->isAccessiblePublicly());

        $settings->setEnabled(false);
        $this->assertFalse($settings->isAccessiblePublicly());

        $settings->setEnabled(true)->setPublicAccess(false);
        $this->assertFalse($settings->isAccessiblePublicly());

        $settings->setEnabled(true)->setPublicAccess(true);
        $this->assertTrue($settings->isAccessiblePublicly());
    }
}
