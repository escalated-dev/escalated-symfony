<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

/**
 * Settings for the knowledge base module.
 */
class KnowledgeBaseSettings
{
    public function __construct(
        private bool $enabled = true,
        private bool $publicAccess = true,
        private bool $feedbackEnabled = true,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function isPublicAccess(): bool
    {
        return $this->publicAccess;
    }

    public function setPublicAccess(bool $publicAccess): self
    {
        $this->publicAccess = $publicAccess;

        return $this;
    }

    public function isFeedbackEnabled(): bool
    {
        return $this->feedbackEnabled;
    }

    public function setFeedbackEnabled(bool $feedbackEnabled): self
    {
        $this->feedbackEnabled = $feedbackEnabled;

        return $this;
    }

    /**
     * Check whether the KB is accessible to the public (no auth required).
     */
    public function isAccessiblePublicly(): bool
    {
        return $this->enabled && $this->publicAccess;
    }
}
