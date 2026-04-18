<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

class DemoController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $appEnv,
    ) {
    }

    #[Route('/', name: 'home')]
    public function home(): Response
    {
        if ('demo' === $this->appEnv) {
            return $this->redirectToRoute('demo_picker');
        }

        return new Response('Escalated Symfony demo host. Set APP_ENV=demo to enable the click-to-login page.', 200);
    }

    #[Route('/demo', name: 'demo_picker')]
    public function picker(): Response
    {
        $this->guardDemo();

        $users = $this->em->getRepository(User::class)->findBy([], ['id' => 'ASC']);

        return $this->render('demo/picker.html.twig', ['users' => $users]);
    }

    #[Route('/demo/login/{id}', name: 'demo_login', methods: ['POST'])]
    public function loginAs(int $id, Request $request): RedirectResponse
    {
        $this->guardDemo();

        $user = $this->em->getRepository(User::class)->find($id);
        if (!$user) {
            throw new NotFoundHttpException('No such demo user.');
        }

        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $this->container->get('security.token_storage')->setToken($token);

        $session = $request->getSession();
        $session->set('_security_main', serialize($token));
        $session->save();

        return $this->redirect('/support/agent');
    }

    #[Route('/demo/logout', name: 'demo_logout', methods: ['POST'])]
    public function logout(Request $request): RedirectResponse
    {
        $this->guardDemo();

        $this->container->get('security.token_storage')->setToken(null);
        $request->getSession()->invalidate();

        return $this->redirectToRoute('demo_picker');
    }

    private function guardDemo(): void
    {
        if ('demo' !== $this->appEnv) {
            throw new NotFoundHttpException();
        }
    }
}
