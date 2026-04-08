<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Broadcasting;

use Escalated\Symfony\Broadcasting\BroadcastSettings;
use PHPUnit\Framework\TestCase;

class BroadcastSettingsTest extends TestCase
{
    public function testDefaultsDisabled(): void
    {
        $settings = new BroadcastSettings();

        $this->assertFalse($settings->isEnabled());
        $this->assertSame(BroadcastSettings::DRIVER_NONE, $settings->getDriver());
        $this->assertSame([], $settings->getBroadcastEvents());
        $this->assertNull($settings->getMercureHubUrl());
    }

    public function testEnabledWithMercure(): void
    {
        $settings = new BroadcastSettings(
            driver: BroadcastSettings::DRIVER_MERCURE,
            enabled: true,
            mercureHubUrl: 'https://hub.example.com/.well-known/mercure',
        );

        $this->assertTrue($settings->isEnabled());
        $this->assertSame(BroadcastSettings::DRIVER_MERCURE, $settings->getDriver());
    }

    public function testEnabledWithNoneDriverStillDisabled(): void
    {
        $settings = new BroadcastSettings(driver: BroadcastSettings::DRIVER_NONE, enabled: true);

        $this->assertFalse($settings->isEnabled());
    }

    public function testShouldBroadcastWhenDisabled(): void
    {
        $settings = new BroadcastSettings();

        $this->assertFalse($settings->shouldBroadcast('status_changed'));
    }

    public function testShouldBroadcastAllEventsWhenListEmpty(): void
    {
        $settings = new BroadcastSettings(
            driver: BroadcastSettings::DRIVER_MERCURE,
            enabled: true,
            broadcastEvents: [],
        );

        $this->assertTrue($settings->shouldBroadcast('status_changed'));
        $this->assertTrue($settings->shouldBroadcast('replied'));
    }

    public function testShouldBroadcastOnlyListedEvents(): void
    {
        $settings = new BroadcastSettings(
            driver: BroadcastSettings::DRIVER_CUSTOM,
            enabled: true,
            broadcastEvents: ['status_changed', 'replied'],
        );

        $this->assertTrue($settings->shouldBroadcast('status_changed'));
        $this->assertTrue($settings->shouldBroadcast('replied'));
        $this->assertFalse($settings->shouldBroadcast('tag_added'));
    }

    public function testFluentSetters(): void
    {
        $settings = new BroadcastSettings();
        $result = $settings->setDriver(BroadcastSettings::DRIVER_CUSTOM)
            ->setEnabled(true)
            ->setBroadcastEvents(['created'])
            ->setMercureHubUrl('https://hub.test');

        $this->assertSame($settings, $result);
        $this->assertTrue($settings->isEnabled());
    }
}
