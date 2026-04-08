<?php

declare(strict_types=1);

namespace Escalated\Symfony\Widget;

class WidgetSettings
{
    public function __construct(
        private bool $enabled = false,
        private string $primaryColor = '#4F46E5',
        private string $position = 'bottom-right',
        private string $greeting = 'Hi! How can we help?',
        private bool $allowGuestTickets = true,
        private bool $showArticles = true,
        private int $maxArticlesShown = 5,
        private array $allowedOrigins = [],
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

    public function getPrimaryColor(): string
    {
        return $this->primaryColor;
    }

    public function setPrimaryColor(string $primaryColor): self
    {
        $this->primaryColor = $primaryColor;

        return $this;
    }

    public function getPosition(): string
    {
        return $this->position;
    }

    public function setPosition(string $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function getGreeting(): string
    {
        return $this->greeting;
    }

    public function setGreeting(string $greeting): self
    {
        $this->greeting = $greeting;

        return $this;
    }

    public function isAllowGuestTickets(): bool
    {
        return $this->allowGuestTickets;
    }

    public function setAllowGuestTickets(bool $allowGuestTickets): self
    {
        $this->allowGuestTickets = $allowGuestTickets;

        return $this;
    }

    public function isShowArticles(): bool
    {
        return $this->showArticles;
    }

    public function setShowArticles(bool $showArticles): self
    {
        $this->showArticles = $showArticles;

        return $this;
    }

    public function getMaxArticlesShown(): int
    {
        return $this->maxArticlesShown;
    }

    public function setMaxArticlesShown(int $maxArticlesShown): self
    {
        $this->maxArticlesShown = $maxArticlesShown;

        return $this;
    }

    public function getAllowedOrigins(): array
    {
        return $this->allowedOrigins;
    }

    public function setAllowedOrigins(array $allowedOrigins): self
    {
        $this->allowedOrigins = $allowedOrigins;

        return $this;
    }

    public function isOriginAllowed(string $origin): bool
    {
        if (empty($this->allowedOrigins)) {
            return true;
        }

        return in_array($origin, $this->allowedOrigins, true);
    }

    public function toPublicConfig(): array
    {
        return [
            'enabled' => $this->enabled,
            'primary_color' => $this->primaryColor,
            'position' => $this->position,
            'greeting' => $this->greeting,
            'allow_guest_tickets' => $this->allowGuestTickets,
            'show_articles' => $this->showArticles,
        ];
    }
}
