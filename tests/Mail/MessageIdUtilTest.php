<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Mail;

use Escalated\Symfony\Mail\MessageIdUtil;
use PHPUnit\Framework\TestCase;

/**
 * Pure-function tests for MessageIdUtil. Mirrors the NestJS / Spring /
 * WordPress / .NET / Phoenix / Laravel / Rails / Django / Adonis / Go
 * reference test suites.
 */
class MessageIdUtilTest extends TestCase
{
    private const DOMAIN = 'support.example.com';

    private const SECRET = 'test-secret-long-enough-for-hmac';

    public function testBuildMessageIdInitialTicket(): void
    {
        $this->assertSame(
            '<ticket-42@support.example.com>',
            MessageIdUtil::buildMessageId(42, null, self::DOMAIN)
        );
    }

    public function testBuildMessageIdReplyForm(): void
    {
        $this->assertSame(
            '<ticket-42-reply-7@support.example.com>',
            MessageIdUtil::buildMessageId(42, 7, self::DOMAIN)
        );
    }

    public function testParseTicketIdRoundTrips(): void
    {
        $initial = MessageIdUtil::buildMessageId(42, null, self::DOMAIN);
        $reply = MessageIdUtil::buildMessageId(42, 7, self::DOMAIN);
        $this->assertSame(42, MessageIdUtil::parseTicketIdFromMessageId($initial));
        $this->assertSame(42, MessageIdUtil::parseTicketIdFromMessageId($reply));
    }

    public function testParseTicketIdAcceptsValueWithoutBrackets(): void
    {
        $this->assertSame(99, MessageIdUtil::parseTicketIdFromMessageId('ticket-99@example.com'));
    }

    public function testParseTicketIdReturnsNullForUnrelatedInput(): void
    {
        $this->assertNull(MessageIdUtil::parseTicketIdFromMessageId(null));
        $this->assertNull(MessageIdUtil::parseTicketIdFromMessageId(''));
        $this->assertNull(MessageIdUtil::parseTicketIdFromMessageId('<random@mail.com>'));
        $this->assertNull(MessageIdUtil::parseTicketIdFromMessageId('ticket-abc@example.com'));
    }

    public function testBuildReplyToIsStable(): void
    {
        $first = MessageIdUtil::buildReplyTo(42, self::SECRET, self::DOMAIN);
        $again = MessageIdUtil::buildReplyTo(42, self::SECRET, self::DOMAIN);
        $this->assertSame($first, $again);
        $this->assertMatchesRegularExpression(
            '/^reply\+42\.[a-f0-9]{8}@support\.example\.com$/',
            $first
        );
    }

    public function testBuildReplyToDiffersAcrossTickets(): void
    {
        $a = MessageIdUtil::buildReplyTo(42, self::SECRET, self::DOMAIN);
        $b = MessageIdUtil::buildReplyTo(43, self::SECRET, self::DOMAIN);
        $this->assertNotSame(
            substr($a, 0, strpos($a, '@')),
            substr($b, 0, strpos($b, '@'))
        );
    }

    public function testVerifyReplyToRoundTrips(): void
    {
        $address = MessageIdUtil::buildReplyTo(42, self::SECRET, self::DOMAIN);
        $this->assertSame(42, MessageIdUtil::verifyReplyTo($address, self::SECRET));
    }

    public function testVerifyReplyToAcceptsLocalPartOnly(): void
    {
        $address = MessageIdUtil::buildReplyTo(42, self::SECRET, self::DOMAIN);
        $local = substr($address, 0, strpos($address, '@'));
        $this->assertSame(42, MessageIdUtil::verifyReplyTo($local, self::SECRET));
    }

    public function testVerifyReplyToRejectsTamperedSignature(): void
    {
        $address = MessageIdUtil::buildReplyTo(42, self::SECRET, self::DOMAIN);
        $at = strpos($address, '@');
        $local = substr($address, 0, $at);
        $last = $local[strlen($local) - 1];
        $tampered = substr($local, 0, -1).('0' === $last ? '1' : '0').substr($address, $at);
        $this->assertNull(MessageIdUtil::verifyReplyTo($tampered, self::SECRET));
    }

    public function testVerifyReplyToRejectsWrongSecret(): void
    {
        $address = MessageIdUtil::buildReplyTo(42, self::SECRET, self::DOMAIN);
        $this->assertNull(MessageIdUtil::verifyReplyTo($address, 'different-secret'));
    }

    public function testVerifyReplyToRejectsMalformedInput(): void
    {
        $this->assertNull(MessageIdUtil::verifyReplyTo(null, self::SECRET));
        $this->assertNull(MessageIdUtil::verifyReplyTo('', self::SECRET));
        $this->assertNull(MessageIdUtil::verifyReplyTo('alice@example.com', self::SECRET));
        $this->assertNull(MessageIdUtil::verifyReplyTo('reply@example.com', self::SECRET));
        $this->assertNull(MessageIdUtil::verifyReplyTo('reply+abc.deadbeef@example.com', self::SECRET));
    }

    public function testVerifyReplyToCaseInsensitiveHex(): void
    {
        $address = MessageIdUtil::buildReplyTo(42, self::SECRET, self::DOMAIN);
        $this->assertSame(42, MessageIdUtil::verifyReplyTo(strtoupper($address), self::SECRET));
    }
}
