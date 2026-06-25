<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\AgentCapacity;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Service\CapacityService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin view of per-agent ticket capacity plus an endpoint to adjust an
 * agent's concurrent ceiling. Mirrors the Laravel CapacityController.
 */
#[Route('/admin/capacity', name: 'escalated.admin.capacity.')]
class CapacityController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UiRendererInterface $renderer,
        private readonly CapacityService $capacityService,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/Capacity/Index', [
            'capacities' => array_map([$this, 'serialize'], $this->capacityService->getAllCapacities()),
        ]);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH', 'PUT'])]
    public function update(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $capacity = $this->em->getRepository(AgentCapacity::class)->find($id);
        if (null === $capacity) {
            throw $this->createNotFoundException('Capacity record not found.');
        }

        $payload = $this->payload($request);
        $max = (int) ($payload['max_concurrent'] ?? 0);
        if ($max < 1 || $max > 999) {
            $this->addFlash('error', 'Max concurrent must be between 1 and 999.');

            return $this->redirectToRoute('escalated.admin.capacity.index');
        }

        $capacity->setMaxConcurrent($max);
        $this->em->flush();

        $this->addFlash('success', 'Capacity updated.');

        return $this->redirectToRoute('escalated.admin.capacity.index');
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        if ('' !== ($json = (string) $request->getContent())
            && str_starts_with((string) $request->headers->get('Content-Type'), 'application/json')
        ) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $request->request->all();
    }

    /** @return array<string, mixed> */
    private function serialize(AgentCapacity $cap): array
    {
        return [
            'id' => $cap->getId(),
            'user_id' => $cap->getUserId(),
            'channel' => $cap->getChannel(),
            'max_concurrent' => $cap->getMaxConcurrent(),
            'current_count' => $cap->getCurrentCount(),
            'load_percentage' => $cap->loadPercentage(),
        ];
    }
}
