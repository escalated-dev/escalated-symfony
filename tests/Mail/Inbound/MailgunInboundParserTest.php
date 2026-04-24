<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Mail\Inbound;

use Escalated\Symfony\Mail\Inbound\MailgunInboundParser;
use PHPUnit\Framework\TestCase;

final class MailgunInboundParserTest extends TestCase
{
    /** @var array<string,string> */
    private array $sampleFormData;

    protected function setUp(): void
    {
        $this->sampleFormData = [
            'sender' => 'customer@example.com',
            'from' => 'Customer <customer@example.com>',
            'recipient' => 'support+abc@support.example.com',
            'To' => 'support+abc@support.example.com',
            'subject' => '[ESC-00042] Help',
            'body-plain' => 'Plain body',
            'body-html' => '<p>HTML body</p>',
            'Message-Id' => '<mailgun-incoming@mail.client>',
            'In-Reply-To' => '<ticket-42@support.example.com>',
            'References' => '<ticket-42@support.example.com>',
            'attachments' => '[{"name":"report.pdf","content-type":"application/pdf","size":5120,"url":"https://mailgun.example/att/abc"}]',
        ];
    }

    public function testNameIsMailgun(): void
    {
        $this->assertSame('mailgun', (new MailgunInboundParser())->name());
    }

    public function testParseExtractsCoreFields(): void
    {
        $message = (new MailgunInboundParser())->parse($this->sampleFormData);

        $this->assertSame('customer@example.com', $message->fromEmail);
        $this->assertSame('Customer', $message->fromName);
        $this->assertSame('support+abc@support.example.com', $message->toEmail);
        $this->assertSame('[ESC-00042] Help', $message->subject);
        $this->assertSame('Plain body', $message->bodyText);
        $this->assertSame('<p>HTML body</p>', $message->bodyHtml);
    }

    public function testParseExtractsThreadingHeaders(): void
    {
        $message = (new MailgunInboundParser())->parse($this->sampleFormData);

        $this->assertSame('<ticket-42@support.example.com>', $message->inReplyTo);
        $this->assertSame('<ticket-42@support.example.com>', $message->references);
    }

    public function testParseProviderHostedAttachments(): void
    {
        $message = (new MailgunInboundParser())->parse($this->sampleFormData);

        $this->assertCount(1, $message->attachments);
        $attachment = $message->attachments[0];
        $this->assertSame('report.pdf', $attachment->name);
        $this->assertSame('application/pdf', $attachment->contentType);
        $this->assertSame(5120, $attachment->sizeBytes);
        $this->assertSame('https://mailgun.example/att/abc', $attachment->downloadUrl);
        // Mailgun hosts content — no inline bytes.
        $this->assertNull($attachment->content);
    }

    public function testParseHandlesMalformedAttachmentsJson(): void
    {
        $data = $this->sampleFormData;
        $data['attachments'] = 'not json';

        $message = (new MailgunInboundParser())->parse($data);

        $this->assertEmpty($message->attachments);
    }

    public function testParseFallsBackSenderToFrom(): void
    {
        $data = [
            'from' => 'only-from@example.com',
            'recipient' => 'support@example.com',
            'subject' => 'hi',
        ];

        $message = (new MailgunInboundParser())->parse($data);

        $this->assertSame('only-from@example.com', $message->fromEmail);
    }

    public function testParseReturnsNullFromNameForBareEmail(): void
    {
        $data = [
            'sender' => 'bareemail@example.com',
            'from' => 'bareemail@example.com',
            'recipient' => 'support@example.com',
            'subject' => 'hi',
        ];

        $message = (new MailgunInboundParser())->parse($data);

        $this->assertNull($message->fromName);
    }

    public function testParseStripsQuotesFromFromName(): void
    {
        $data = [
            'sender' => 'jane@example.com',
            'from' => '"Jane Doe" <jane@example.com>',
            'recipient' => 'support@example.com',
            'subject' => 'hi',
        ];

        $message = (new MailgunInboundParser())->parse($data);

        $this->assertSame('Jane Doe', $message->fromName);
    }
}
