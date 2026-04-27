<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Mail\Inbound;

use Escalated\Symfony\Mail\Inbound\SESInboundParser;
use Escalated\Symfony\Mail\Inbound\SESSubscriptionConfirmationException;
use PHPUnit\Framework\TestCase;

class SESInboundParserTest extends TestCase
{
    private SESInboundParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SESInboundParser();
    }

    public function testNameIsSes(): void
    {
        $this->assertSame('ses', $this->parser->name());
    }

    public function testSubscriptionConfirmationThrowsWithSubscribeUrl(): void
    {
        $envelope = [
            'Type' => 'SubscriptionConfirmation',
            'TopicArn' => 'arn:aws:sns:us-east-1:123:escalated-inbound',
            'SubscribeURL' => 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&Token=x',
            'Token' => 'abc',
        ];

        try {
            $this->parser->parse($envelope);
            $this->fail('expected SESSubscriptionConfirmationException');
        } catch (SESSubscriptionConfirmationException $ex) {
            $this->assertSame('arn:aws:sns:us-east-1:123:escalated-inbound', $ex->topicArn);
            $this->assertStringContainsString('ConfirmSubscription', $ex->subscribeUrl);
            $this->assertSame('abc', $ex->token);
        }
    }

    public function testNotificationExtractsThreadingMetadata(): void
    {
        $sesMessage = [
            'notificationType' => 'Received',
            'mail' => [
                'source' => 'alice@example.com',
                'destination' => ['support@example.com'],
                'headers' => [
                    ['name' => 'From', 'value' => 'Alice <alice@example.com>'],
                    ['name' => 'To', 'value' => 'support@example.com'],
                    ['name' => 'Subject', 'value' => '[ESC-42] Re: Help'],
                    ['name' => 'Message-ID', 'value' => '<external-xyz@mail.alice.com>'],
                    ['name' => 'In-Reply-To', 'value' => '<ticket-42@support.example.com>'],
                    ['name' => 'References', 'value' => '<ticket-42@support.example.com> <prev@mail.com>'],
                ],
                'commonHeaders' => [
                    'from' => ['Alice <alice@example.com>'],
                    'to' => ['support@example.com'],
                    'subject' => '[ESC-42] Re: Help',
                ],
            ],
        ];
        $envelope = [
            'Type' => 'Notification',
            'Message' => json_encode($sesMessage),
        ];

        $msg = $this->parser->parse($envelope);

        $this->assertSame('alice@example.com', $msg->fromEmail);
        $this->assertSame('Alice', $msg->fromName);
        $this->assertSame('support@example.com', $msg->toEmail);
        $this->assertSame('[ESC-42] Re: Help', $msg->subject);
        $this->assertSame('<external-xyz@mail.alice.com>', $msg->messageId);
        $this->assertSame('<ticket-42@support.example.com>', $msg->inReplyTo);
        $this->assertStringContainsString('ticket-42@support.example.com', (string) $msg->references);
        $this->assertSame('Alice <alice@example.com>', $msg->headers['From']);
    }

    public function testNotificationDecodesPlainTextBody(): void
    {
        $mime = "From: alice@example.com\r\n"
            ."To: support@example.com\r\n"
            ."Subject: Hi\r\n"
            ."Content-Type: text/plain; charset=\"utf-8\"\r\n"
            ."\r\n"
            .'This is the plain text body.';

        $envelope = [
            'Type' => 'Notification',
            'Message' => json_encode([
                'mail' => [
                    'commonHeaders' => [
                        'from' => ['alice@example.com'],
                        'to' => ['support@example.com'],
                        'subject' => 'Hi',
                    ],
                ],
                'content' => base64_encode($mime),
            ]),
        ];

        $msg = $this->parser->parse($envelope);

        $this->assertStringContainsString('plain text body', (string) $msg->bodyText);
    }

    public function testNotificationDecodesMultipartBody(): void
    {
        $boundary = 'boundary-abc';
        $mime = "From: alice@example.com\r\n"
            ."To: support@example.com\r\n"
            ."Subject: Hi\r\n"
            ."Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n"
            ."\r\n"
            ."--{$boundary}\r\n"
            ."Content-Type: text/plain; charset=\"utf-8\"\r\n"
            ."\r\n"
            ."Plain body\r\n"
            ."--{$boundary}\r\n"
            ."Content-Type: text/html; charset=\"utf-8\"\r\n"
            ."\r\n"
            ."<p>HTML body</p>\r\n"
            ."--{$boundary}--\r\n";

        $envelope = [
            'Type' => 'Notification',
            'Message' => json_encode([
                'mail' => [
                    'commonHeaders' => [
                        'from' => ['alice@example.com'],
                        'to' => ['support@example.com'],
                        'subject' => 'Hi',
                    ],
                ],
                'content' => base64_encode($mime),
            ]),
        ];

        $msg = $this->parser->parse($envelope);

        $this->assertStringContainsString('Plain body', (string) $msg->bodyText);
        $this->assertStringContainsString('<p>HTML body</p>', (string) $msg->bodyHtml);
    }

    public function testNotificationMissingContentLeavesBodyNull(): void
    {
        $envelope = [
            'Type' => 'Notification',
            'Message' => json_encode([
                'mail' => [
                    'commonHeaders' => [
                        'from' => ['alice@example.com'],
                        'to' => ['support@example.com'],
                        'subject' => 'Hi',
                    ],
                ],
            ]),
        ];

        $msg = $this->parser->parse($envelope);

        $this->assertNull($msg->bodyText);
        $this->assertNull($msg->bodyHtml);
        $this->assertSame('alice@example.com', $msg->fromEmail);
    }

    public function testUnknownEnvelopeTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unsupported SNS envelope type/');
        $this->parser->parse(['Type' => 'UnknownType']);
    }

    public function testMissingMessageFieldThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no Message body/');
        $this->parser->parse(['Type' => 'Notification']);
    }

    public function testMalformedMessageJsonThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/');
        $this->parser->parse([
            'Type' => 'Notification',
            'Message' => 'not json at all',
        ]);
    }

    public function testFallsBackToHeadersArrayForThreadingFields(): void
    {
        $envelope = [
            'Type' => 'Notification',
            'Message' => json_encode([
                'mail' => [
                    'headers' => [
                        ['name' => 'Message-ID', 'value' => '<fallback@mail.com>'],
                        ['name' => 'In-Reply-To', 'value' => '<ticket-99@support.example.com>'],
                    ],
                    'commonHeaders' => [
                        'from' => ['alice@example.com'],
                        'to' => ['support@example.com'],
                        'subject' => 'Fallback',
                    ],
                ],
            ]),
        ];

        $msg = $this->parser->parse($envelope);

        $this->assertSame('<fallback@mail.com>', $msg->messageId);
        $this->assertSame('<ticket-99@support.example.com>', $msg->inReplyTo);
    }
}
