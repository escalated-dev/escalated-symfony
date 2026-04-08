<?php

declare(strict_types=1);

namespace Escalated\Symfony\Mail;

/**
 * Holds branding configuration for email templates.
 */
class BrandingSettings
{
    public function __construct(
        private string $companyName = 'Support',
        private ?string $logoUrl = null,
        private string $primaryColor = '#4F46E5',
        private string $footerText = 'Powered by Escalated',
        private ?string $replyToAddress = null,
    ) {
    }

    public function getCompanyName(): string
    {
        return $this->companyName;
    }

    public function setCompanyName(string $companyName): self
    {
        $this->companyName = $companyName;

        return $this;
    }

    public function getLogoUrl(): ?string
    {
        return $this->logoUrl;
    }

    public function setLogoUrl(?string $logoUrl): self
    {
        $this->logoUrl = $logoUrl;

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

    public function getFooterText(): string
    {
        return $this->footerText;
    }

    public function setFooterText(string $footerText): self
    {
        $this->footerText = $footerText;

        return $this;
    }

    public function getReplyToAddress(): ?string
    {
        return $this->replyToAddress;
    }

    public function setReplyToAddress(?string $replyToAddress): self
    {
        $this->replyToAddress = $replyToAddress;

        return $this;
    }

    /**
     * Convert settings to an array suitable for passing to Twig templates.
     */
    public function toArray(): array
    {
        return [
            'company_name' => $this->companyName,
            'logo_url' => $this->logoUrl,
            'primary_color' => $this->primaryColor,
            'footer_text' => $this->footerText,
            'reply_to_address' => $this->replyToAddress,
        ];
    }
}
