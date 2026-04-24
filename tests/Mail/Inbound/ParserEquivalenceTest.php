<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Mail\Inbound;

use Escalated\Symfony\Mail\Inbound\MailgunInboundParser;
use Escalated\Symfony\Mail\Inbound\PostmarkInboundParser;
use Escalated\Symfony\Mail\Inbound\SESInboundParser;
use PHPUnit\Framework\TestCase;

/**
 * Parser-equivalence tests: the same logical email, expressed in
 * each provider's native webhook payload shape, should normalize to
 * the same InboundMessage metadata. Parser equivalence at this layer
 * guarantees a reply delivered via any provider routes to the same
 * ticket via the same threading chain.
 *
 * Mirrors escalated-go#37 + escalated-dotnet#31 + escalated-spring#34
 * + escalated-phoenix#43. Adding a fourth provider in the future can
 * reuse the same SAMPLE + build*Payload builders and get contract
 * validation for free.
 */
class ParserEquivalenceTest extends TestCase
{
    private const SAMPLE = [
        'fromEmail' => 'alice@example.com',
        'fromName' => 'Alice',
        'toEmail' => 'support@example.com',
        'subject' => 'Re: Help with invoice',
        'bodyText' => 'Thanks for the quick response.',
        'messageId' => '<external-reply-xyz@mail.alice.com>',
        'inReplyTo' => '<ticket-42@support.example.com>',
        'references' => '<ticket-42@support.example.com>',
    ];

    private static function buildPostmarkPayload(array $e): array
    {
        return [
            'FromFull' => ['Email' => $e['fromEmail'], 'Name' => $e['fromName']],
            'To' => $e['toEmail'],
            'Subject' => $e['subject'],
            'TextBody' => $e['bodyText'],
            'Headers' => [
                ['Name' => 'Message-ID', 'Value' => $e['messageId']],
                ['Name' => 'In-Reply-To', 'Value' => $e['inReplyTo']],
                ['Name' => 'References', 'Value' => $e['references']],
            ],
        ];
    }

    private static function buildMailgunPayload(array $e): array
    {
        return [
            'sender' => $e['fromEmail'],
            'from' => $e['fromName'] . ' <' . $e['fromEmail'] . '>',
            'recipient' => $e['toEmail'],
            'subject' => $e['subject'],
            'body-plain' => $e['bodyText'],
            'Message-Id' => $e['messageId'],
            'In-Reply-To' => $e['inReplyTo'],
            'References' => $e['references'],
        ];
    }

    private static function buildSesPayload(array $e): array
    {
        // Include full raw MIME as base64 so body extraction is
        // exercised — keeps the payload close to a real SES delivery.
        $mime = "From: {$e['fromName']} <{$e['fromEmail']}>\r\n"
            . "To: {$e['toEmail']}\r\n"
            . "Subject: {$e['subject']}\r\n"
            . "Message-ID: {$e['messageId']}\r\n"
            . "In-Reply-To: {$e['inReplyTo']}\r\n"
            . "References: {$e['references']}\r\n"
            . "Content-Type: text/plain; charset=\"utf-8\"\r\n"
            . "\r\n"
            . $e['bodyText'];

        $sesMessage = [
            'notificationType' => 'Received',
            'mail' => [
                'source' => $e['fromEmail'],
                'destination' => [$e['toEmail']],
                'headers' => [
                    ['name' => 'From', 'value' => $e['fromName'] . ' <' . $e['fromEmail'] . '>'],
                    ['name' => 'To', 'value' => $e['toEmail']],
                    ['name' => 'Subject', 'value' => $e['subject']],
                    ['name' => 'Message-ID', 'value' => $e['messageId']],
                    ['name' => 'In-Reply-To', 'value' => $e['inReplyTo']],
                    ['name' => 'References', 'value' => $e['references']],
                ],
                'commonHeaders' => [
                    'from' => [$e['fromName'] . ' <' . $e['fromEmail'] . '>'],
                    'to' => [$e['toEmail']],
                    'subject' => $e['subject'],
                ],
            ],
            'content' => base64_encode($mime),
        ];

        return [
            'Type' => 'Notification',
            'Message' => json_encode($sesMessage),
        ];
    }

    public function testNormalizesToSameMessage(): void
    {
        $postmark = (new PostmarkInboundParser())->parse(self::buildPostmarkPayload(self::SAMPLE));
        $mailgun = (new MailgunInboundParser())->parse(self::buildMailgunPayload(self::SAMPLE));
        $ses = (new SESInboundParser())->parse(self::buildSesPayload(self::SAMPLE));

        foreach (['postmark' => $postmark, 'mailgun' => $mailgun, 'ses' => $ses] as $name => $msg) {
            $this->assertSame(self::SAMPLE['fromEmail'], $msg->fromEmail, "{$name}: fromEmail");
            $this->assertSame(self::SAMPLE['toEmail'], $msg->toEmail, "{$name}: toEmail");
            $this->assertSame(self::SAMPLE['subject'], $msg->subject, "{$name}: subject");
            $this->assertSame(self::SAMPLE['inReplyTo'], $msg->inReplyTo, "{$name}: inReplyTo");
            $this->assertSame(self::SAMPLE['references'], $msg->references, "{$name}: references");
        }
    }

    public function testBodyExtractionMatches(): void
    {
        $postmark = (new PostmarkInboundParser())->parse(self::buildPostmarkPayload(self::SAMPLE));
        $mailgun = (new MailgunInboundParser())->parse(self::buildMailgunPayload(self::SAMPLE));
        $ses = (new SESInboundParser())->parse(self::buildSesPayload(self::SAMPLE));

        $this->assertSame(self::SAMPLE['bodyText'], $postmark->bodyText);
        $this->assertSame(self::SAMPLE['bodyText'], $mailgun->bodyText);
        $this->assertSame(self::SAMPLE['bodyText'], $ses->bodyText);
    }
}
