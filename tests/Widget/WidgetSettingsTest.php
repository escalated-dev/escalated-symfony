<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Widget;

use Escalated\Symfony\Widget\WidgetSettings;
use PHPUnit\Framework\TestCase;

class WidgetSettingsTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $settings = new WidgetSettings();

        $this->assertFalse($settings->isEnabled());
        $this->assertSame('#4F46E5', $settings->getPrimaryColor());
        $this->assertSame('bottom-right', $settings->getPosition());
        $this->assertSame('Hi! How can we help?', $settings->getGreeting());
        $this->assertTrue($settings->isAllowGuestTickets());
        $this->assertTrue($settings->isShowArticles());
        $this->assertSame(5, $settings->getMaxArticlesShown());
        $this->assertSame([], $settings->getAllowedOrigins());
    }

    public function testCustomValues(): void
    {
        $settings = new WidgetSettings(
            enabled: true,
            primaryColor: '#FF0000',
            position: 'bottom-left',
            greeting: 'Hello!',
            allowGuestTickets: false,
            showArticles: false,
            maxArticlesShown: 10,
            allowedOrigins: ['https://example.com'],
        );

        $this->assertTrue($settings->isEnabled());
        $this->assertSame('#FF0000', $settings->getPrimaryColor());
        $this->assertSame('bottom-left', $settings->getPosition());
        $this->assertSame('Hello!', $settings->getGreeting());
        $this->assertFalse($settings->isAllowGuestTickets());
        $this->assertFalse($settings->isShowArticles());
        $this->assertSame(10, $settings->getMaxArticlesShown());
        $this->assertSame(['https://example.com'], $settings->getAllowedOrigins());
    }

    public function testFluentSetters(): void
    {
        $settings = new WidgetSettings();
        $result = $settings->setEnabled(true)
            ->setPrimaryColor('#333')
            ->setPosition('top-right')
            ->setGreeting('Yo!')
            ->setAllowGuestTickets(false)
            ->setShowArticles(false)
            ->setMaxArticlesShown(3)
            ->setAllowedOrigins(['https://a.com', 'https://b.com']);

        $this->assertSame($settings, $result);
        $this->assertTrue($settings->isEnabled());
        $this->assertSame('#333', $settings->getPrimaryColor());
    }

    public function testIsOriginAllowedWithEmptyList(): void
    {
        $settings = new WidgetSettings();

        $this->assertTrue($settings->isOriginAllowed('https://anything.com'));
    }

    public function testIsOriginAllowedWithRestrictions(): void
    {
        $settings = new WidgetSettings(allowedOrigins: ['https://allowed.com']);

        $this->assertTrue($settings->isOriginAllowed('https://allowed.com'));
        $this->assertFalse($settings->isOriginAllowed('https://blocked.com'));
    }

    public function testToPublicConfig(): void
    {
        $settings = new WidgetSettings(
            enabled: true,
            primaryColor: '#123456',
            position: 'bottom-left',
            greeting: 'Welcome',
            allowGuestTickets: true,
            showArticles: false,
        );

        $config = $settings->toPublicConfig();

        $this->assertTrue($config['enabled']);
        $this->assertSame('#123456', $config['primary_color']);
        $this->assertSame('bottom-left', $config['position']);
        $this->assertSame('Welcome', $config['greeting']);
        $this->assertTrue($config['allow_guest_tickets']);
        $this->assertFalse($config['show_articles']);
        // Should NOT include sensitive config like allowed_origins
        $this->assertArrayNotHasKey('allowed_origins', $config);
        $this->assertArrayNotHasKey('max_articles_shown', $config);
    }
}
