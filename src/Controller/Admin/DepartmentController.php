<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Repository\DepartmentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/admin/departments', name: 'escalated.admin.departments.')]
class DepartmentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DepartmentRepository $departmentRepository,
        private readonly UiRendererInterface $renderer,
        private readonly SluggerInterface $slugger,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $departments = $this->departmentRepository->findAll();

        return $this->renderer->render('Escalated/Admin/Departments/Index', [
            'departments' => $departments,
        ]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $department = new Department();
        $department->setName($request->request->get('name', ''));
        $department->setSlug(
            strtolower((string) $this->slugger->slug($department->getName()))
        );
        $department->setDescription($request->request->get('description'));
        $department->setIsActive((bool) $request->request->get('is_active', true));

        $this->em->persist($department);
        $this->em->flush();

        $this->addFlash('success', 'Department created.');

        return $this->redirectToRoute('escalated.admin.departments.index');
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'])]
    public function update(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $department = $this->departmentRepository->find($id);
        if (null === $department) {
            throw $this->createNotFoundException('Department not found.');
        }

        if ($request->request->has('name')) {
            $department->setName($request->request->get('name'));
            $department->setSlug(
                strtolower((string) $this->slugger->slug($department->getName()))
            );
        }
        if ($request->request->has('description')) {
            $department->setDescription($request->request->get('description'));
        }
        if ($request->request->has('is_active')) {
            $department->setIsActive((bool) $request->request->get('is_active'));
        }

        $this->em->flush();

        $this->addFlash('success', 'Department updated.');

        return $this->redirectToRoute('escalated.admin.departments.index');
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $department = $this->departmentRepository->find($id);
        if (null === $department) {
            throw $this->createNotFoundException('Department not found.');
        }

        $this->em->remove($department);
        $this->em->flush();

        $this->addFlash('success', 'Department deleted.');

        return $this->redirectToRoute('escalated.admin.departments.index');
    }
}
