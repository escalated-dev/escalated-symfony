<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the shared Inertia pages need from a controller that takes their
 * writes: the body whether it came as JSON (Inertia's useForm and router post
 * JSON) or as a form, and Laravel's "back with errors" answer to bad input.
 *
 * Not a route source: the route loader imports the Admin/, Agent/, Customer/
 * and Widget/ directories only.
 */
abstract class UiController extends AbstractController
{
    /**
     * @return array<string, mixed>
     */
    protected function payload(Request $request): array
    {
        $json = (string) $request->getContent();
        if ('' !== $json && str_contains((string) $request->headers->get('Content-Type'), 'json')) {
            $decoded = json_decode($json, true);

            return \is_array($decoded) ? $decoded : [];
        }

        return $request->request->all();
    }

    /**
     * Back to the page the request came from (same host only), else to the
     * given route: Laravel's `back()`.
     *
     * @param array<string, mixed> $parameters
     */
    protected function back(Request $request, string $fallbackRoute, array $parameters = []): RedirectResponse
    {
        $referer = (string) $request->headers->get('referer', '');
        $path = parse_url($referer, \PHP_URL_PATH);
        $host = parse_url($referer, \PHP_URL_HOST);
        if (\is_string($path) && str_starts_with($path, '/') && !str_starts_with($path, '//') && (null === $host || $host === $request->getHost())) {
            $query = parse_url($referer, \PHP_URL_QUERY);

            return new RedirectResponse($path.(\is_string($query) ? '?'.$query : ''), Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute($fallbackRoute, $parameters, Response::HTTP_SEE_OTHER);
    }

    /**
     * Rejected input: a 422 for a JSON client, otherwise the errors flashed
     * (`errors`, keyed by field, as Inertia reads them) and back.
     *
     * @param array<string, string> $errors
     * @param array<string, mixed>  $parameters
     */
    protected function invalid(Request $request, array $errors, string $fallbackRoute, array $parameters = []): Response
    {
        if (!$request->headers->has('X-Inertia') && 'json' === $request->getPreferredFormat()) {
            return new JsonResponse(['message' => reset($errors), 'errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('errors', $errors);
        $this->addFlash('error', (string) reset($errors));

        return $this->back($request, $fallbackRoute, $parameters);
    }
}
