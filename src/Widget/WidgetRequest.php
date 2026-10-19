<?php

declare(strict_types=1);

namespace Escalated\Symfony\Widget;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Department;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Request and response conventions shared by the widget endpoints, so both
 * the shared widget (`@escalated-dev/escalated`) and older clients work:
 *
 * - errors carry `message` (what the widget shows) next to `error`;
 * - the visitor's name and email may be sent as `name`/`email` (the widget)
 *   or `guest_name`/`guest_email` (the original field names);
 * - a guest token may be sent as `X-Guest-Token` or `Authorization: Bearer`.
 */
final class WidgetRequest
{
    /**
     * The JSON body as an array; anything else is treated as empty.
     *
     * @return array<string, mixed>
     */
    public static function body(Request $request): array
    {
        $data = json_decode($request->getContent(), true);

        return \is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $data */
    public static function guestName(array $data): ?string
    {
        return self::text($data['guest_name'] ?? $data['name'] ?? null);
    }

    /** @param array<string, mixed> $data */
    public static function guestEmail(array $data): ?string
    {
        return self::text($data['guest_email'] ?? $data['email'] ?? null);
    }

    public static function guestToken(Request $request): ?string
    {
        $token = $request->headers->get('X-Guest-Token');
        if (null !== $token && '' !== $token) {
            return $token;
        }

        $authorization = (string) $request->headers->get('Authorization', '');
        if (1 === preg_match('/^Bearer\s+(\S+)$/i', $authorization, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * The active department a visitor picked. `false` when one was named but
     * does not exist or is inactive; null when none was named.
     *
     * @param array<string, mixed> $data
     */
    public static function department(EntityManagerInterface $em, array $data): Department|false|null
    {
        $id = $data['department_id'] ?? null;
        if (null === $id || '' === $id) {
            return null;
        }
        if (!\is_int($id) && !(\is_string($id) && ctype_digit($id))) {
            return false;
        }

        $department = $em->find(Department::class, (int) $id);

        return $department instanceof Department && $department->isActive() ? $department : false;
    }

    /**
     * @param array<string, string> $headers
     */
    public static function error(string $message, int $status, array $headers = []): JsonResponse
    {
        return new JsonResponse(['error' => $message, 'message' => $message], $status, $headers);
    }

    private static function text(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}
