<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Entity;

use Escalated\Symfony\Entity\Webhook;
use Escalated\Symfony\Entity\WebhookDelivery;
use PHPUnit\Framework\TestCase;

class WebhookTest extends TestCase
{
    public function testDefaults(): void
    {
        $webhook = new Webhook();

        $this->assertNull($webhook->getId());
        $this->assertSame('', $webhook->getUrl());
        $this->assertSame([], $webhook->getEvents());
        $this->assertNull($webhook->getSecret());
        $this->assertTrue($webhook->isActive());
        $this->assertCount(0, $webhook->getDeliveries());
    }

    public function testSubscribedTo(): void
    {
        $webhook = (new Webhook())->setEvents(['ticket.created', 'reply.created']);

        $this->assertTrue($webhook->subscribedTo('ticket.created'));
        $this->assertTrue($webhook->subscribedTo('reply.created'));
        $this->assertFalse($webhook->subscribedTo('ticket.updated'));
    }

    public function testSetEventsIsReindexed(): void
    {
        $webhook = (new Webhook())->setEvents([2 => 'ticket.created', 5 => 'reply.created']);

        $this->assertSame(['ticket.created', 'reply.created'], $webhook->getEvents());
    }

    public function testSetters(): void
    {
        $webhook = (new Webhook())
            ->setUrl('https://example.test/hook')
            ->setSecret('shh')
            ->setActive(false);

        $this->assertSame('https://example.test/hook', $webhook->getUrl());
        $this->assertSame('shh', $webhook->getSecret());
        $this->assertFalse($webhook->isActive());
    }

    public function testDeliveryDefaults(): void
    {
        $delivery = new WebhookDelivery();

        $this->assertNull($delivery->getId());
        $this->assertSame('', $delivery->getEvent());
        $this->assertNull($delivery->getPayload());
        $this->assertNull($delivery->getResponseCode());
        $this->assertNull($delivery->getResponseBody());
        $this->assertSame(0, $delivery->getAttempts());
        $this->assertNull($delivery->getDeliveredAt());
        $this->assertFalse($delivery->isSuccess());
    }

    public function testDeliveryIsSuccessReflectsStatusRange(): void
    {
        $delivery = new WebhookDelivery();

        $delivery->setResponseCode(200);
        $this->assertTrue($delivery->isSuccess());

        $delivery->setResponseCode(299);
        $this->assertTrue($delivery->isSuccess());

        $delivery->setResponseCode(300);
        $this->assertFalse($delivery->isSuccess());

        $delivery->setResponseCode(404);
        $this->assertFalse($delivery->isSuccess());

        $delivery->setResponseCode(0);
        $this->assertFalse($delivery->isSuccess());
    }

    public function testDeliveryWebhookRelation(): void
    {
        $webhook = new Webhook();
        $delivery = (new WebhookDelivery())->setWebhook($webhook);

        $this->assertSame($webhook, $delivery->getWebhook());
    }
}
