<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Entity;

use Escalated\Symfony\Entity\Contact;
use PHPUnit\Framework\TestCase;

class ContactTest extends TestCase
{
    // ---------------------------------------------------------------------
    // normalizeEmail (pure)
    // ---------------------------------------------------------------------

    public function testNormalizeEmailLowercases(): void
    {
        $this->assertSame('alice@example.com', Contact::normalizeEmail('ALICE@Example.COM'));
    }

    public function testNormalizeEmailTrimsWhitespace(): void
    {
        $this->assertSame('alice@example.com', Contact::normalizeEmail('  alice@example.com  '));
    }

    public function testNormalizeEmailHandlesNull(): void
    {
        $this->assertSame('', Contact::normalizeEmail(null));
        $this->assertSame('', Contact::normalizeEmail(''));
    }

    // ---------------------------------------------------------------------
    // decideAction (pure)
    // ---------------------------------------------------------------------

    public function testDecideActionCreateWhenNoExisting(): void
    {
        $this->assertSame('create', Contact::decideAction(null, 'Alice'));
    }

    public function testDecideActionReturnExistingWhenExistingHasName(): void
    {
        $existing = (new Contact())->setEmail('alice@example.com')->setName('Alice');
        $this->assertSame('return-existing', Contact::decideAction($existing, 'Different'));
    }

    public function testDecideActionUpdateNameWhenExistingNameIsBlank(): void
    {
        $existing = (new Contact())->setEmail('alice@example.com')->setName(null);
        $this->assertSame('update-name', Contact::decideAction($existing, 'Alice'));

        $existing->setName('');
        $this->assertSame('update-name', Contact::decideAction($existing, 'Alice'));
    }

    public function testDecideActionReturnExistingWhenNoIncomingName(): void
    {
        $existing = (new Contact())->setEmail('alice@example.com')->setName(null);
        $this->assertSame('return-existing', Contact::decideAction($existing, null));
        $this->assertSame('return-existing', Contact::decideAction($existing, ''));
    }

    // ---------------------------------------------------------------------
    // Setter normalization
    // ---------------------------------------------------------------------

    public function testSetEmailNormalizesOnWrite(): void
    {
        $c = (new Contact())->setEmail('  MIX@Case.COM ');
        $this->assertSame('mix@case.com', $c->getEmail());
    }

    public function testMetadataRoundTrips(): void
    {
        $c = (new Contact())->setMetadata(['source' => 'widget', 'count' => 3]);
        $this->assertSame(['source' => 'widget', 'count' => 3], $c->getMetadata());
    }

    public function testDefaults(): void
    {
        $c = new Contact();
        $this->assertSame('', $c->getEmail());
        $this->assertNull($c->getName());
        $this->assertNull($c->getUserId());
        $this->assertSame([], $c->getMetadata());
    }
}
