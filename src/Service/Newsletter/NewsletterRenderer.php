<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service\Newsletter;

use Escalated\Symfony\Entity\Newsletter\NewsletterDelivery;
use Twig\Environment;

/**
 * Renders a delivery to themed HTML. Markdown rendering is host-pluggable
 * via the constructor's $markdownToHtml callable (null falls back to a
 * minimal escape+paragraph wrapper). Twig is used for themes.
 */
class NewsletterRenderer
{
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public function __construct(
        private readonly Environment $twig,
        private readonly string $baseUrl,
        private readonly string $defaultTheme = 'default',
        private readonly bool $trackingEnabled = true,
        private readonly ?\Closure $markdownToHtml = null,
        private readonly array $brand = [],
    ) {}

    public function render(NewsletterDelivery $delivery, $newsletter, $contact, ?array $template = null): string
    {
        $bodyMd = $newsletter->getBodyMarkdown() ?: ($template['body_markdown'] ?? '');
        $themeSlug = $newsletter->getTheme() ?: ($template['theme'] ?? $this->defaultTheme);

        $body = $this->renderMarkdown($bodyMd ?? '');
        $body = $this->resolveMergeFields($body, $contact, $delivery);

        $themed = $this->twig->render("@EscalatedNewsletter/{$themeSlug}.html.twig", [
            'subject' => $newsletter->getSubject(),
            'body' => $body,
            'unsubscribe_url' => $this->unsubscribeUrl($delivery),
            'view_in_browser_url' => $this->viewInBrowserUrl($delivery),
            'brand' => array_merge(['name' => 'Support', 'accent' => '#2563eb'], $this->brand),
        ]);

        if (!$this->trackingEnabled) return $themed;
        return $this->injectPixel($this->rewriteLinks($themed, $delivery), $delivery);
    }

    public function unsubscribeUrl(NewsletterDelivery $d): string
    {
        return rtrim($this->baseUrl, '/') . '/escalated/n/u/' . $d->getTrackingToken();
    }

    public function viewInBrowserUrl(NewsletterDelivery $d): string
    {
        return rtrim($this->baseUrl, '/') . '/escalated/n/v/' . $d->getTrackingToken();
    }

    private function renderMarkdown(string $md): string
    {
        if ($this->markdownToHtml !== null) {
            return ($this->markdownToHtml)($md);
        }
        $escaped = htmlspecialchars($md, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return '<p>' . implode('</p><p>', preg_split('/\n{2,}/', $escaped)) . '</p>';
    }

    private function resolveMergeFields(string $html, $contact, NewsletterDelivery $delivery): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function ($m) use ($contact, $delivery) {
            return htmlspecialchars($this->resolvePath(trim($m[1]), $contact, $delivery), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }, $html);
    }

    private function resolvePath(string $path, $contact, NewsletterDelivery $delivery): string
    {
        $name = method_exists($contact, 'getName') ? (string) $contact->getName() : '';
        $email = method_exists($contact, 'getEmail') ? (string) $contact->getEmail() : '';
        if ($path === 'contact.name') return $name;
        if ($path === 'contact.first_name') return explode(' ', $name)[0] ?? '';
        if ($path === 'contact.email') return $email;
        if ($path === 'unsubscribe_url') return $this->unsubscribeUrl($delivery);
        if ($path === 'view_in_browser_url') return $this->viewInBrowserUrl($delivery);
        if (str_starts_with($path, 'contact.metadata.')) {
            $key = substr($path, strlen('contact.metadata.'));
            $meta = method_exists($contact, 'getMetadata') ? ($contact->getMetadata() ?? []) : [];
            return isset($meta[$key]) ? (string) $meta[$key] : '';
        }
        return '';
    }

    private function rewriteLinks(string $html, NewsletterDelivery $d): string
    {
        $unsub = $this->unsubscribeUrl($d);
        $view = $this->viewInBrowserUrl($d);
        return preg_replace_callback(
            '#(<a\s[^>]*\bhref=)(["\'])(.*?)\2#i',
            function ($m) use ($d, $unsub, $view) {
                $prefix = $m[1]; $quote = $m[2]; $href = $m[3];
                if ($href === '' || str_starts_with($href, '#')) return $m[0];
                $scheme = strtolower(parse_url($href, PHP_URL_SCHEME) ?? '');
                if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) return "{$prefix}{$quote}#{$quote}";
                if (in_array($scheme, ['mailto', 'tel'], true)) return $m[0];
                if (str_starts_with($href, $unsub) || str_starts_with($href, $view)) return $m[0];
                $encoded = rtrim(strtr(base64_encode($href), '+/', '-_'), '=');
                $tracked = rtrim($this->baseUrl, '/') . "/escalated/n/c/{$d->getTrackingToken()}?u={$encoded}";
                return "{$prefix}{$quote}{$tracked}{$quote}";
            },
            $html,
        );
    }

    private function injectPixel(string $html, NewsletterDelivery $d): string
    {
        $url = rtrim($this->baseUrl, '/') . "/escalated/n/o/{$d->getTrackingToken()}.gif";
        $pixel = '<img src="' . htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" width="1" height="1" alt="" />';
        if (str_contains($html, '</body>')) return str_replace('</body>', $pixel . '</body>', $html);
        return $html . $pixel;
    }
}
