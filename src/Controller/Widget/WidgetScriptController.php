<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Widget;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the standalone widget bundle so any site can load it with a
 * <script> tag pointing at this install. The script derives the widget API
 * path from its own URL, so it lives next to the API under
 * {route_prefix}/widget/api.
 *
 * The bundle is public/widget/escalated-widget.js, vendored from the npm
 * package @escalated-dev/escalated (dist/widget/escalated-widget.js).
 */
#[AsController]
final class WidgetScriptController
{
    public const BUNDLE = __DIR__.'/../../../public/widget/escalated-widget.js';

    #[Route('/widget/api/embed.js', name: 'escalated.widget.script', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $path = self::BUNDLE;
        if (!is_file($path)) {
            throw new NotFoundHttpException('Widget script not found.');
        }

        $etag = '"'.md5_file($path).'"';
        $response = new Response('', Response::HTTP_OK, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
            'Access-Control-Allow-Origin' => '*',
        ]);
        $response->setPublic();
        $response->setMaxAge(3600);
        $response->setEtag($etag);
        // Public whatever the host's session listener saw on this request.
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        if ($response->isNotModified($request)) {
            return $response;
        }

        return $response->setContent((string) file_get_contents($path));
    }
}
