<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/auth', name: 'escalated.api.auth.')]
class AuthController extends AbstractController
{
    /**
     * Returns the current authenticated user's info and Escalated roles.
     */
    #[Route('/me', name: 'me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->getUser();

        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        return $this->json([
            'data' => [
                'identifier' => $user->getUserIdentifier(),
                'roles' => $user->getRoles(),
                'is_agent' => $this->isGranted('ESCALATED_AGENT'),
                'is_admin' => $this->isGranted('ESCALATED_ADMIN'),
            ],
        ]);
    }
}
