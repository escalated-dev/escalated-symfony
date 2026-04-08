<?php

declare(strict_types=1);

namespace Escalated\Symfony\Security;

use Escalated\Symfony\Service\KnowledgeBaseSettings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Kernel event listener that guards KB routes based on settings.
 *
 * - If KB is disabled, all KB routes return 404.
 * - If KB is not public, unauthenticated users are denied.
 */
class KnowledgeBaseGuard
{
    public function __construct(
        private readonly KnowledgeBaseSettings $settings,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly string $kbRoutePrefix = 'escalated.kb.',
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $routeName = $event->getRequest()->attributes->get('_route', '');
        if (!str_starts_with($routeName, $this->kbRoutePrefix)) {
            return;
        }

        if (!$this->settings->isEnabled()) {
            $event->setResponse(new JsonResponse(
                ['error' => 'Knowledge base is disabled.'],
                Response::HTTP_NOT_FOUND,
            ));

            return;
        }

        if (!$this->settings->isPublicAccess()) {
            $token = $this->tokenStorage->getToken();
            if (null === $token || !$token->getUser()) {
                $event->setResponse(new JsonResponse(
                    ['error' => 'Authentication required.'],
                    Response::HTTP_UNAUTHORIZED,
                ));
            }
        }
    }

    public function getKbRoutePrefix(): string
    {
        return $this->kbRoutePrefix;
    }
}
