<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Mail;

use Escalated\Symfony\Mail\BrandingSettings;
use PHPUnit\Framework\TestCase;

class BrandingSettingsTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $branding = new BrandingSettings();

        $this->assertSame('Support', $branding->getCompanyName());
        $this->assertNull($branding->getLogoUrl());
        $this->assertSame('#4F46E5', $branding->getPrimaryColor());
        $this->assertSame('Powered by Escalated', $branding->getFooterText());
        $this->assertNull($branding->getReplyToAddress());
    }

    public function testCustomValues(): void
    {
        $branding = new BrandingSettings(
            companyName: 'Acme Corp',
            logoUrl: 'https://example.com/logo.png',
            primaryColor: '#FF0000',
            footerText: 'Acme Support Team',
            replyToAddress: 'support@acme.com',
        );

        $this->assertSame('Acme Corp', $branding->getCompanyName());
        $this->assertSame('https://example.com/logo.png', $branding->getLogoUrl());
        $this->assertSame('#FF0000', $branding->getPrimaryColor());
        $this->assertSame('Acme Support Team', $branding->getFooterText());
        $this->assertSame('support@acme.com', $branding->getReplyToAddress());
    }

    public function testFluentSetters(): void
    {
        $branding = new BrandingSettings();
        $result = $branding->setCompanyName('Test Co')
            ->setLogoUrl('https://test.com/logo.png')
            ->setPrimaryColor('#00FF00')
            ->setFooterText('Test Footer')
            ->setReplyToAddress('test@test.com');

        $this->assertSame($branding, $result);
        $this->assertSame('Test Co', $branding->getCompanyName());
    }

    public function testToArray(): void
    {
        $branding = new BrandingSettings(
            companyName: 'Acme',
            logoUrl: 'https://acme.com/logo.png',
            primaryColor: '#333',
            footerText: 'Acme Footer',
            replyToAddress: 'reply@acme.com',
        );

        $array = $branding->toArray();

        $this->assertSame('Acme', $array['company_name']);
        $this->assertSame('https://acme.com/logo.png', $array['logo_url']);
        $this->assertSame('#333', $array['primary_color']);
        $this->assertSame('Acme Footer', $array['footer_text']);
        $this->assertSame('reply@acme.com', $array['reply_to_address']);
    }
}
