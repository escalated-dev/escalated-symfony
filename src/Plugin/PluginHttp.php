<?php

declare(strict_types=1);

namespace Escalated\Symfony\Plugin;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP contract version 1 between the host and a plugin's endpoints and
 * webhooks (the SDK's docs/http-contract.md, escalated-laravel's PluginHttp):
 * what a handler is given, and how its answer becomes a response.
 */
final class PluginHttp
{
    /** The caller's credentials never reach plugin code. */
    private const WITHHELD = ['cookie', 'authorization', 'php-auth-user', 'php-auth-pw', 'php-auth-digest', 'x-xsrf-token', 'x-csrf-token'];

    /** Headers a plugin may not set on its response. */
    private const BLOCKED_RESPONSE_HEADERS = ['connection', 'content-length', 'transfer-encoding', 'keep-alive', 'upgrade', 'proxy-authenticate', 'proxy-authorization', 'te', 'trailer', 'set-cookie'];

    /**
     * @param array<string, string> $params the `:param` values matched in the manifest path
     *
     * @return array<string, mixed>
     */
    public static function request(Request $request, array $params): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $name = strtolower((string) $name);
            if (\in_array($name, self::WITHHELD, true) || str_starts_with($name, 'proxy-')) {
                continue;
            }
            $headers[$name] = implode(', ', array_map('strval', $values));
        }

        $content = (string) $request->getContent();
        $body = $request->request->all();
        if (str_contains((string) $request->headers->get('Content-Type'), 'json')) {
            $decoded = '' === $content ? null : json_decode($content, true);
            $body = \is_array($decoded) ? $decoded : ([] === $body ? null : $body);
        } elseif ([] === $body) {
            $body = null;
        }

        return [
            'httpContract' => 1,
            'rawBodyBase64' => base64_encode($content),
            'body' => $body,
            // JSON objects even when empty: the SDK types both as records.
            'params' => (object) $params,
            'query' => (object) $request->query->all(),
            'headers' => (object) $headers,
            'clientIp' => $request->getClientIp(),
        ];
    }

    /**
     * A handler's ordinary value is JSON with 200; the SDK's httpResponse()
     * envelope sets the status, format and headers. A malformed envelope is a
     * 502.
     */
    public static function response(mixed $result): Response
    {
        if (!\is_array($result) || !\array_key_exists('__escalated_http', $result)) {
            return new JsonResponse($result ?? new \stdClass());
        }

        $status = $result['status'] ?? null;
        $format = $result['format'] ?? null;
        $headers = $result['headers'] ?? null;
        if (1 !== $result['__escalated_http'] || !\is_int($status) || $status < 200 || $status > 599
            || !\in_array($format, ['json', 'text'], true) || !\is_array($headers) || !\array_key_exists('body', $result)
            || ('text' === $format && !\is_string($result['body']))) {
            return self::invalid();
        }

        $out = [];
        foreach ($headers as $name => $value) {
            $name = strtolower((string) $name);
            if (!preg_match('/^[!#$%&\'*+.^_`|~0-9a-z-]+$/D', $name) || \in_array($name, self::BLOCKED_RESPONSE_HEADERS, true)
                || \array_key_exists($name, $out) || !\is_string($value) || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                return self::invalid();
            }
            $out[$name] = $value;
        }

        if ('json' === $format) {
            return new JsonResponse($result['body'], $status, $out);
        }

        return new Response($result['body'], $status, $out + ['content-type' => 'text/plain; charset=UTF-8']);
    }

    private static function invalid(): Response
    {
        return new JsonResponse(['message' => 'Invalid plugin HTTP response.'], Response::HTTP_BAD_GATEWAY);
    }
}
