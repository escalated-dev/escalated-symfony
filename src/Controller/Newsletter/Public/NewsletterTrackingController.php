<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Newsletter\Public;

use Escalated\Symfony\Service\Newsletter\NewsletterTracker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/escalated/n', name: 'escalated.newsletters.public.')]
class NewsletterTrackingController extends AbstractController
{
    private const PIXEL_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=';

    public function __construct(
        private readonly NewsletterTracker $tracker,
        private readonly bool $enabled = false,
    ) {
    }

    #[Route('/o/{token}', name: 'open', requirements: ['token' => '[A-Za-z0-9._-]+'], methods: ['GET'])]
    public function open(string $token): Response
    {
        $this->abortUnlessEnabled();
        $this->tracker->recordOpen((string) preg_replace('/\.(gif|png|jpg)$/i', '', $token));

        return new Response((string) base64_decode(self::PIXEL_BASE64, true), Response::HTTP_OK, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    #[Route('/c/{token}', name: 'click', requirements: ['token' => '[A-Za-z0-9_-]+'], methods: ['GET'])]
    public function click(string $token, Request $request): Response
    {
        $this->abortUnlessEnabled();

        $encoded = (string) $request->query->get('u', '');
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        if (false === $decoded || !\is_string($decoded)) {
            return new Response('Bad request', Response::HTTP_BAD_REQUEST);
        }
        $scheme = strtolower((string) parse_url($decoded, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return new Response('Bad request', Response::HTTP_BAD_REQUEST);
        }

        $this->tracker->recordClick($token, $decoded);

        return new RedirectResponse($decoded, Response::HTTP_FOUND);
    }

    private function abortUnlessEnabled(): void
    {
        if (!$this->enabled) {
            throw $this->createNotFoundException();
        }
    }
}
