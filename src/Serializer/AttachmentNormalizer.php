<?php

declare(strict_types=1);

namespace Escalated\Symfony\Serializer;

use Escalated\Symfony\Entity\Attachment;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Ensures every serialized Attachment object includes a `url` field.
 *
 * The `url` is resolved using Attachment::resolveUrl(), which applies
 * the following priority:
 *   1. Explicit URL stored on the entity (e.g. a pre-signed S3 link).
 *   2. Base storage URL + path (configured via escalated.storage.base_url).
 *   3. Fallback download route: /escalated/attachments/{id}/download.
 */
#[Autoconfigure(tags: ['serializer.normalizer'])]
class AttachmentNormalizer implements NormalizerInterface
{
    public function __construct(
        private readonly string $baseStorageUrl = '',
    ) {
    }

    /**
     * @param Attachment $object
     */
    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        return $object->toArray('' !== $this->baseStorageUrl ? $this->baseStorageUrl : null);
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Attachment;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            Attachment::class => true,
        ];
    }
}
