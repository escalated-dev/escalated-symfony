<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Escalated\Symfony\Service\MentionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The composer's @mention picker, as escalated-laravel's
 * AgentSearchController: up to 10 agents whose name or email contains `q`,
 * as `[{id, name, email}]`; `[]` without a query.
 */
class AgentSearchController extends AbstractController
{
    public function __construct(private readonly MentionService $mentions)
    {
    }

    #[Route('/admin/agents/search', name: 'escalated.admin.agents.search', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return new JsonResponse($this->mentions->searchAgents((string) $request->query->get('q', '')));
    }
}
