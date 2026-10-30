<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Fixtures;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Stand-ins for failures the suite cannot otherwise provoke on a real route,
 * mounted by {@see \Escalated\Symfony\Tests\Kernel\EscalatedTestKernel} under
 * `escalated.error_probe.*` names (Escalated's own) and one host name.
 */
final class ErrorProbeController
{
    public function thrown(int $status): Response
    {
        throw match ($status) {
            429 => new TooManyRequestsHttpException(30, 'upstream detail'),
            503 => new ServiceUnavailableHttpException(null, 'upstream detail'),
            default => new \RuntimeException('SQLSTATE[42S02] escalated_secret_table missing'),
        };
    }

    public function returned(int $status): Response
    {
        return new JsonResponse(['error' => 'upstream detail'], $status);
    }

    public function hostMissing(): Response
    {
        throw new NotFoundHttpException('host page');
    }
}
