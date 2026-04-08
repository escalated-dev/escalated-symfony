<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Agent;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\SavedView;
use Escalated\Symfony\Repository\SavedViewRepository;
use Escalated\Symfony\Service\TicketService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/agent/views', name: 'escalated.agent.views.')]
class SavedViewController extends AbstractController
{
    public function __construct(
        private readonly SavedViewRepository $viewRepository,
        private readonly TicketService $ticketService,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $userId = (int) $this->getUser()->getUserIdentifier();
        $views = $this->viewRepository->findForUser($userId);

        $data = array_map(fn (SavedView $v) => [
            'id' => $v->getId(),
            'name' => $v->getName(),
            'filters' => $v->getFilters(),
            'sort_by' => $v->getSortBy(),
            'sort_dir' => $v->getSortDir(),
            'is_shared' => $v->isShared(),
            'is_default' => $v->isDefault(),
            'position' => $v->getPosition(),
            'color' => $v->getColor(),
            'icon' => $v->getIcon(),
        ], $views);

        return $this->json(['data' => $data]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $data = json_decode($request->getContent(), true) ?? [];
        $userId = (int) $this->getUser()->getUserIdentifier();

        $view = new SavedView();
        $view->setName($data['name'] ?? 'Untitled View');
        $view->setUserId($userId);
        $view->setFilters($data['filters'] ?? []);
        $view->setSortBy($data['sort_by'] ?? null);
        $view->setSortDir($data['sort_dir'] ?? null);
        $view->setIsShared($data['is_shared'] ?? false);
        $view->setIsDefault($data['is_default'] ?? false);
        $view->setPosition($data['position'] ?? 0);
        $view->setColor($data['color'] ?? null);
        $view->setIcon($data['icon'] ?? null);

        $this->em->persist($view);
        $this->em->flush();

        return $this->json(['data' => ['id' => $view->getId(), 'name' => $view->getName()]], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $view = $this->viewRepository->find($id);
        if (null === $view) {
            return $this->json(['error' => 'View not found.'], Response::HTTP_NOT_FOUND);
        }

        $userId = (int) $this->getUser()->getUserIdentifier();
        if ($view->getUserId() !== $userId) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (isset($data['name'])) {
            $view->setName($data['name']);
        }
        if (isset($data['filters'])) {
            $view->setFilters($data['filters']);
        }
        if (isset($data['sort_by'])) {
            $view->setSortBy($data['sort_by']);
        }
        if (isset($data['sort_dir'])) {
            $view->setSortDir($data['sort_dir']);
        }
        if (isset($data['is_shared'])) {
            $view->setIsShared($data['is_shared']);
        }
        if (isset($data['is_default'])) {
            $view->setIsDefault($data['is_default']);
        }
        if (isset($data['color'])) {
            $view->setColor($data['color']);
        }
        if (isset($data['icon'])) {
            $view->setIcon($data['icon']);
        }

        $this->em->flush();

        return $this->json(['data' => ['id' => $view->getId(), 'name' => $view->getName()]]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $view = $this->viewRepository->find($id);
        if (null === $view) {
            return $this->json(['error' => 'View not found.'], Response::HTTP_NOT_FOUND);
        }

        $userId = (int) $this->getUser()->getUserIdentifier();
        if ($view->getUserId() !== $userId) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $this->em->remove($view);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/reorder', name: 'reorder', methods: ['POST'])]
    public function reorder(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $data = json_decode($request->getContent(), true) ?? [];
        $order = $data['order'] ?? [];
        $userId = (int) $this->getUser()->getUserIdentifier();

        foreach ($order as $position => $viewId) {
            $view = $this->viewRepository->find($viewId);
            if (null !== $view && $view->getUserId() === $userId) {
                $view->setPosition($position);
            }
        }

        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/{id}/tickets', name: 'tickets', methods: ['GET'])]
    public function tickets(int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $view = $this->viewRepository->find($id);
        if (null === $view) {
            return $this->json(['error' => 'View not found.'], Response::HTTP_NOT_FOUND);
        }

        $userId = (int) $this->getUser()->getUserIdentifier();
        if ($view->getUserId() !== $userId && !$view->isShared()) {
            return $this->json(['error' => 'Access denied.'], Response::HTTP_FORBIDDEN);
        }

        $tickets = $this->ticketService->list($view->toQueryFilters());

        return $this->json(['data' => $tickets]);
    }
}
