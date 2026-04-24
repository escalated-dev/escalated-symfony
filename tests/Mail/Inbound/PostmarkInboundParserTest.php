<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Mail\Inbound;

use Escalated\Symfony\Mail\Inbound\PostmarkInboundParser;
use PHPUnit\Framework\TestCase;

final class PostmarkInboundParserTest extends TestCase
{
    private array $samplePayload;

    protected function setUp(): void
    {
        $this->samplePayload = [
            'FromName' => 'Customer',
            'MessageID' => '22c74902-a0c1-4511-804f2-341342852c90',
            'FromFull' => ['Email' => 'customer@example.com', 'Name' => 'Customer'],
            'To' => 'support+abc@support.example.com',
            'ToFull' => [['Email' => 'support+abc@support.example.com', 'Name' => '']],
            'OriginalRecipient' => 'support+abc@support.example.com',
            'Subject' => '[ESC-00042] Help',
            'TextBody' => 'Plain body',
            'HtmlBody' => '<p>HTML body</p>',
            'Headers' => [
                ['Name' => 'Message-ID', 'Value' => '<abc@mail.client>'],
                ['Name' => 'In-Reply-To', 'Value' => '<ticket-42@support.example.com>'],
                ['Name' => 'References', 'Value' => '<ticket-42@support.example.com>'],
            ],
            'Attachments' => [
                [
                    'Name' => 'report.pdf',
                    'Content' => 'aGVsbG8=',
                    'ContentType' => 'application/pdf',
                    'ContentLength' => 5,
                ],
            ],
        ];
    }

    public function testNameIsPostmark(): void
    {
        $this->assertSame('postmark', (new PostmarkInboundParser())->name());
    }

    public function testParseExtractsCoreFields(): void
    {
        $message = (new PostmarkInboundParser())->parse($this->samplePayload);

        $this->assertSame('customer@example.com', $message->fromEmail);
        $this->assertSame('Customer', $message->fromName);
        $this->assertSame('support+abc@support.example.com', $message->toEmail);
        $this->assertSame('[ESC-00042] Help', $message->subject);
        $this->assertSame('Plain body', $message->bodyText);
        $this->assertSame('<p>HTML body</p>', $message->bodyHtml);
    }

    public function testParseExtractsThreadingHeadersFromHeadersArray(): void
    {
        $message = (new PostmarkInboundParser())->parse($this->samplePayload);

        $this->assertSame('<ticket-42@support.example.com>', $message->inReplyTo);
        $this->assertSame('<ticket-42@support.example.com>', $message->references);
    }

    public function testParseDecodesBase64Attachment(): void
    {
        $message = (new PostmarkInboundParser())->parse($this->samplePayload);

        $this->assertCount(1, $message->attachments);
        $attachment = $message->attachments[0];
        $this->assertSame('report.pdf', $attachment->name);
        $this->assertSame('application/pdf', $attachment->contentType);
        $this->assertSame(5, $attachment->sizeBytes);
        $this->assertSame('hello', $attachment->content);
    }

    public function testParseHandlesMinimalPayload(): void
    {
        $payload = [
            'FromFull' => ['Email' => 'a@b.com'],
            'ToFull' => [['Email' => 'c@d.com']],
            'Subject' => 'minimal',
        ];

        $message = (new PostmarkInboundParser())->parse($payload);

        $this->assertSame('a@b.com', $message->fromEmail);
        $this->assertNull($message->fromName);
        $this->assertSame('c@d.com', $message->toEmail);
        $this->assertSame('minimal', $message->subject);
        $this->assertNull($message->bodyText);
        $this->assertNull($message->inReplyTo);
        $this->assertEmpty($message->attachments);
    }
}
