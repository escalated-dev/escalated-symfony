<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Mail\Inbound;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Mail\Inbound\AttachmentDownloader;
use Escalated\Symfony\Mail\Inbound\AttachmentDownloaderOptions;
use Escalated\Symfony\Mail\Inbound\AttachmentHttpClientInterface;
use Escalated\Symfony\Mail\Inbound\AttachmentHttpResponse;
use Escalated\Symfony\Mail\Inbound\AttachmentStorageInterface;
use Escalated\Symfony\Mail\Inbound\AttachmentTooLargeException;
use Escalated\Symfony\Mail\Inbound\BasicAuth;
use Escalated\Symfony\Mail\Inbound\LocalFileAttachmentStorage;
use Escalated\Symfony\Mail\Inbound\PendingAttachment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AttachmentDownloaderTest extends TestCase
{
    private StubHttpClient $httpClient;
    private RecordingStorage $storage;
    private EntityManagerInterface&MockObject $em;

    private Ticket $ticket;
    private Reply $reply;

    protected function setUp(): void
    {
        $this->httpClient = new StubHttpClient();
        $this->storage = new RecordingStorage();
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->ticket = new Ticket();
        $this->reply = new Reply();

        $this->em->method('find')->willReturnCallback(
            fn (string $class, int $id) => match ($class) {
                Ticket::class => $this->ticket,
                Reply::class => $this->reply,
                default => null,
            }
        );
    }

    private function downloader(?AttachmentDownloaderOptions $options = null): AttachmentDownloader
    {
        return new AttachmentDownloader(
            $this->httpClient,
            $this->storage,
            $this->em,
            $options ?? new AttachmentDownloaderOptions(),
        );
    }

    private static function pending(
        string $url = 'https://provider/att/1',
        string $name = 'report.pdf',
        string $contentType = 'application/pdf',
    ): PendingAttachment {
        return new PendingAttachment(
            name: $name,
            contentType: $contentType,
            sizeBytes: null,
            downloadUrl: $url,
        );
    }

    public function testDownloadHappyPathPersistsAttachment(): void
    {
        $this->httpClient->enqueue(new AttachmentHttpResponse(200, 'hello pdf', ['content-type' => 'application/pdf']));

        $a = $this->downloader()->download(self::pending(), ticketId: 42, replyId: null);

        $this->assertSame('report.pdf', $a->getOriginalFilename());
        $this->assertSame('application/pdf', $a->getMimeType());
        $this->assertSame(9, $a->getSize());
        $this->assertSame($this->ticket, $a->getTicket());
        $this->assertNull($a->getReply());
        $this->assertSame('local', $a->getDisk());
        $this->assertSame('hello pdf', $this->storage->lastContent);
    }

    public function testDownloadWithReplyIdSetsReply(): void
    {
        $this->httpClient->enqueue(new AttachmentHttpResponse(200, 'x'));
        $a = $this->downloader()->download(self::pending(), 42, 7);
        $this->assertSame($this->reply, $a->getReply());
    }

    public function testDownload404ThrowsAndDoesNotPersist(): void
    {
        $this->httpClient->enqueue(new AttachmentHttpResponse(404, 'not found'));

        try {
            $this->downloader()->download(self::pending(), 1);
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $ex) {
            $this->assertStringContainsString('HTTP 404', $ex->getMessage());
        }
        $this->assertSame(0, $this->storage->putCount);
    }

    public function testDownloadOverSizeLimitThrowsAttachmentTooLarge(): void
    {
        $big = str_repeat('x', 100);
        $this->httpClient->enqueue(new AttachmentHttpResponse(200, $big));

        try {
            $this->downloader(new AttachmentDownloaderOptions(maxBytes: 10))
                ->download(self::pending(), 1);
            $this->fail('expected AttachmentTooLargeException');
        } catch (AttachmentTooLargeException $ex) {
            $this->assertSame(100, $ex->actualBytes);
            $this->assertSame(10, $ex->maxBytes);
        }
        $this->assertSame(0, $this->storage->putCount);
    }

    public function testDownloadSendsBasicAuthHeader(): void
    {
        $this->httpClient->enqueue(new AttachmentHttpResponse(200, 'ok'));

        $this->downloader(new AttachmentDownloaderOptions(basicAuth: new BasicAuth('api', 'key-secret')))
            ->download(self::pending(), 1);

        $auth = $this->httpClient->lastHeaders['Authorization'] ?? null;
        $this->assertNotNull($auth, 'Authorization header missing');
        $this->assertStringStartsWith('Basic ', $auth);
        $this->assertSame('api:key-secret', base64_decode(substr($auth, strlen('Basic '))));
    }

    public function testDownloadMissingUrlThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->downloader()->download(self::pending(url: ''), 1);
    }

    public function testDownloadFallsBackToResponseContentType(): void
    {
        $this->httpClient->enqueue(new AttachmentHttpResponse(200, "\0\0\0", ['content-type' => 'image/png']));

        $a = $this->downloader()->download(self::pending(contentType: ''), 1);

        $this->assertSame('image/png', $a->getMimeType());
    }

    /**
     * @dataProvider safeFilenameProvider
     */
    public function testSafeFilenameStripsPathTraversal(?string $input, string $expected): void
    {
        $this->assertSame($expected, AttachmentDownloader::safeFilename($input));
    }

    public static function safeFilenameProvider(): array
    {
        return [
            'parent dirs' => ['../../etc/passwd', 'passwd'],
            'absolute path' => ['/tmp/evil.txt', 'evil.txt'],
            'empty' => ['', 'attachment'],
            'null' => [null, 'attachment'],
            'dotdot' => ['..', 'attachment'],
            'dot' => ['.', 'attachment'],
        ];
    }

    public function testDownloadAllContinuesPastFailures(): void
    {
        $this->httpClient
            ->enqueue(new AttachmentHttpResponse(200, 'ok'))
            ->enqueue(new AttachmentHttpResponse(500, 'nope'))
            ->enqueue(new AttachmentHttpResponse(200, 'ok'));

        $results = $this->downloader()->downloadAll(
            [
                self::pending('https://x/1', 'a'),
                self::pending('https://x/2', 'b'),
                self::pending('https://x/3', 'c'),
            ],
            ticketId: 1
        );

        $this->assertCount(3, $results);
        $this->assertTrue($results[0]->succeeded());
        $this->assertFalse($results[1]->succeeded());
        $this->assertNotNull($results[1]->error);
        $this->assertTrue($results[2]->succeeded());
    }

    public function testLocalFileStorageWritesFile(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'esc-tests-'.uniqid();
        $storage = new LocalFileAttachmentStorage($root);

        $path = $storage->put('hello.txt', 'payload', 'text/plain');

        $this->assertStringStartsWith($root, $path);
        $this->assertStringEndsWith('hello.txt', $path);
        $this->assertSame('payload', file_get_contents($path));

        @unlink($path);
        @rmdir($root);
    }

    public function testLocalFileStorageRejectsEmptyRoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LocalFileAttachmentStorage('');
    }

    public function testLocalFileStorageProducesUniquePaths(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'esc-tests-'.uniqid();
        $storage = new LocalFileAttachmentStorage($root);

        $p1 = $storage->put('x.txt', 'a', 'text/plain');
        usleep(2);
        $p2 = $storage->put('x.txt', 'b', 'text/plain');

        $this->assertNotSame($p1, $p2);

        @unlink($p1);
        @unlink($p2);
        @rmdir($root);
    }
}

/**
 * Minimal {@see AttachmentHttpClientInterface} for tests — returns a
 * FIFO queue of pre-staged responses and records the headers of the
 * most recent call.
 */
class StubHttpClient implements AttachmentHttpClientInterface
{
    /** @var AttachmentHttpResponse[] */
    public array $queue = [];
    public array $lastHeaders = [];

    public function enqueue(AttachmentHttpResponse $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    public function get(string $url, array $headers = []): AttachmentHttpResponse
    {
        $this->lastHeaders = $headers;

        return array_shift($this->queue) ?? new AttachmentHttpResponse(200, '');
    }
}

/**
 * In-memory {@see AttachmentStorageInterface} that records the last
 * put call.
 */
class RecordingStorage implements AttachmentStorageInterface
{
    public string $lastFilename = '';
    public string $lastContent = '';
    public string $lastContentType = '';
    public int $putCount = 0;
    public string $returnPath = '/stored/path/report.pdf';

    public function name(): string
    {
        return 'local';
    }

    public function put(string $filename, string $content, string $contentType): string
    {
        $this->lastFilename = $filename;
        $this->lastContent = $content;
        $this->lastContentType = $contentType;
        ++$this->putCount;

        return $this->returnPath;
    }
}
