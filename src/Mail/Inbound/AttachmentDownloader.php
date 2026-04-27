<?php

declare(strict_types=1);

namespace Escalated\Symfony\Mail\Inbound;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Attachment;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\Ticket;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Fetches provider-hosted attachments surfaced by
 * {@see InboundEmailService::process()} in the
 * {@see ProcessResult::$pendingAttachmentDownloads} list and persists
 * them as {@see Attachment} rows tied to a ticket (and optionally a
 * reply).
 *
 * Mailgun hosts larger attachments behind a URL instead of inlining
 * them in the webhook payload; host apps run this in a background
 * worker after {@code InboundEmailService::process()} returns, so
 * the webhook response can go back to the provider immediately
 * regardless of download latency.
 *
 * Host apps with durable cloud storage needs (S3, Azure Blob, GCS)
 * can implement {@see AttachmentStorageInterface} themselves and
 * pass it to the constructor instead of the reference
 * {@see LocalFileAttachmentStorage}.
 */
class AttachmentDownloader
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly AttachmentHttpClientInterface $httpClient,
        private readonly AttachmentStorageInterface $storage,
        private readonly EntityManagerInterface $em,
        private readonly AttachmentDownloaderOptions $options = new AttachmentDownloaderOptions(),
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Download one {@see PendingAttachment} and persist it.
     *
     * @throws AttachmentTooLargeException when the body exceeds
     *                                     {@link AttachmentDownloaderOptions::$maxBytes}
     * @throws \RuntimeException           on any other failure (HTTP non-2xx,
     *                                     storage write error, missing ticket row, etc.).
     */
    public function download(
        PendingAttachment $pending,
        int $ticketId,
        ?int $replyId = null,
    ): Attachment {
        if ('' === $pending->downloadUrl) {
            throw new \InvalidArgumentException('Pending attachment has no download URL.');
        }

        $headers = [];
        if (null !== $this->options->basicAuth) {
            $encoded = base64_encode(
                $this->options->basicAuth->username.':'.$this->options->basicAuth->password
            );
            $headers['Authorization'] = 'Basic '.$encoded;
        }

        $response = $this->httpClient->get($pending->downloadUrl, $headers);

        if ($response->status < 200 || $response->status >= 300) {
            throw new \RuntimeException(sprintf('Attachment download failed: %s → HTTP %d', $pending->downloadUrl, $response->status));
        }

        $size = strlen($response->body);
        if ($this->options->maxBytes > 0 && $size > $this->options->maxBytes) {
            throw new AttachmentTooLargeException($pending->name, $size, $this->options->maxBytes);
        }

        $contentType = '' !== $pending->contentType
            ? $pending->contentType
            : ($response->headerValue('content-type') ?? 'application/octet-stream');
        $content = $response->body;

        $filename = self::safeFilename($pending->name);
        $path = $this->storage->put($filename, $content, $contentType);

        $ticket = $this->em->find(Ticket::class, $ticketId);
        if (null === $ticket) {
            throw new \RuntimeException("Ticket #{$ticketId} not found");
        }

        $attachment = new Attachment();
        $attachment->setOriginalFilename($filename);
        $attachment->setStoredFilename(basename($path));
        $attachment->setMimeType($contentType);
        $attachment->setSize($size);
        $attachment->setDisk($this->storage->name());
        $attachment->setPath($path);
        $attachment->setTicket($ticket);

        if (null !== $replyId) {
            $reply = $this->em->find(Reply::class, $replyId);
            if (null === $reply) {
                throw new \RuntimeException("Reply #{$replyId} not found");
            }
            $attachment->setReply($reply);
        }

        $this->em->persist($attachment);
        $this->em->flush();

        $this->logger->info(
            '[AttachmentDownloader] Persisted {filename} ({size} bytes) for ticket #{ticketId}',
            ['filename' => $filename, 'size' => $size, 'ticketId' => $ticketId]
        );

        return $attachment;
    }

    /**
     * Download a batch of {@see PendingAttachment}s. Continues past
     * per-attachment failures so a single bad URL doesn't prevent
     * the rest from persisting.
     *
     * @param PendingAttachment[] $pending
     *
     * @return AttachmentDownloadResult[]
     */
    public function downloadAll(array $pending, int $ticketId, ?int $replyId = null): array
    {
        $results = [];
        foreach ($pending as $p) {
            try {
                $attachment = $this->download($p, $ticketId, $replyId);
                $results[] = new AttachmentDownloadResult($p, $attachment, null);
            } catch (\Throwable $ex) {
                $this->logger->warning(
                    '[AttachmentDownloader] Failed to download {url}: {message}',
                    ['url' => $p->downloadUrl, 'message' => $ex->getMessage()]
                );
                $results[] = new AttachmentDownloadResult($p, null, $ex);
            }
        }

        return $results;
    }

    /**
     * Strip path separators so a crafted attachment name like
     * {@code ../../etc/passwd} can't escape the storage root. Falls
     * back to {@code "attachment"} when the input is unusable.
     */
    public static function safeFilename(?string $name): string
    {
        if (null === $name || '' === trim($name)) {
            return 'attachment';
        }
        $normalized = str_replace('\\', '/', trim($name));
        $base = basename($normalized);
        if ('' === $base || '.' === $base || '..' === $base) {
            return 'attachment';
        }

        return $base;
    }
}
