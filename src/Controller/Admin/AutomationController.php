<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Automation;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Service\AutomationRunner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin CRUD over Automation rows + manual `run` trigger.
 *
 * Distinct from the WorkflowEngine controller (event-driven) and
 * MacroController (agent manual). See escalated-developer-context/
 * domain-model/workflows-automations-macros.md.
 */
#[Route('/admin/automations', name: 'escalated.admin.automations.')]
class AutomationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UiRendererInterface $renderer,
        private readonly AutomationRunner $runner,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $automations = $this->em->getRepository(Automation::class)
            ->findBy([], ['position' => 'ASC', 'id' => 'ASC']);

        return $this->renderer->render('Escalated/Admin/Automations/Index', [
            'automations' => array_map([$this, 'serialize'], $automations),
        ]);
    }

    /**
     * Escalated/Admin/Automations/Form for a new automation; escalated-laravel
     * passes no props, so the form starts empty.
     */
    #[Route('/create', name: 'create', methods: ['GET'])]
    public function create(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/Automations/Form');
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $automation = $this->em->getRepository(Automation::class)->find($id);
        if (null === $automation) {
            throw $this->createNotFoundException('Automation not found.');
        }

        return $this->renderer->render('Escalated/Admin/Automations/Form', [
            'automation' => $this->serialize($automation),
        ]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $payload = $this->payload($request);
        if ([] !== ($errors = $this->validate($payload, true))) {
            return $this->invalid($request, $errors);
        }

        $automation = new Automation();
        if (!\array_key_exists('position', $payload)) {
            $max = $this->em->createQueryBuilder()->select('MAX(a.position)')->from(Automation::class, 'a')->getQuery()->getSingleScalarResult();
            $automation->setPosition(null === $max ? 0 : (int) $max + 1);
        }
        $this->applyPayload($automation, $payload);

        $this->em->persist($automation);
        $this->em->flush();

        $this->addFlash('success', 'Automation created.');

        return $this->redirectToRoute('escalated.admin.automations.index');
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH', 'PUT'])]
    public function update(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $automation = $this->em->getRepository(Automation::class)->find($id);
        if (null === $automation) {
            throw $this->createNotFoundException('Automation not found.');
        }

        $payload = $this->payload($request);
        if ([] !== ($errors = $this->validate($payload, false))) {
            return $this->invalid($request, $errors);
        }

        $this->applyPayload($automation, $payload);
        $this->em->flush();

        $this->addFlash('success', 'Automation updated.');

        return $this->redirectToRoute('escalated.admin.automations.index');
    }

    #[Route('/{id}', name: 'destroy', methods: ['DELETE'])]
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $automation = $this->em->getRepository(Automation::class)->find($id);
        if (null === $automation) {
            throw $this->createNotFoundException('Automation not found.');
        }

        $this->em->remove($automation);
        $this->em->flush();

        $this->addFlash('success', 'Automation deleted.');

        return $this->redirectToRoute('escalated.admin.automations.index');
    }

    /**
     * Manually trigger the runner. Useful for admin smoke-tests
     * without waiting for the next scheduled tick.
     */
    #[Route('/run', name: 'run', methods: ['POST'])]
    public function run(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $affected = $this->runner->run();

        return new JsonResponse(['affected' => $affected]);
    }

    /**
     * escalated-laravel's rules: a name of at most 255 characters and at least
     * one condition and one action, with `active` a boolean. On a create every
     * one is required; an update checks the ones it sends, so a client that
     * patches one field keeps working.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, string>
     */
    private function validate(array $payload, bool $creating): array
    {
        $errors = [];
        if ($creating || \array_key_exists('name', $payload)) {
            $name = trim((string) ($payload['name'] ?? ''));
            if ('' === $name) {
                $errors['name'] = 'The name field is required.';
            } elseif (mb_strlen($name) > 255) {
                $errors['name'] = 'The name field must not be greater than 255 characters.';
            }
        }
        foreach (['conditions', 'actions'] as $key) {
            if (($creating || \array_key_exists($key, $payload)) && (!\is_array($payload[$key] ?? null) || [] === $payload[$key])) {
                $errors[$key] = sprintf('The %s field must have at least 1 item.', $key);
            }
        }
        if (\array_key_exists('active', $payload) && null === filter_var($payload['active'], \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE)) {
            $errors['active'] = 'The active field must be true or false.';
        }

        return $errors;
    }

    /**
     * Back to the form with the errors flashed under `errors`, the Inertia
     * convention; a JSON client gets a 422.
     *
     * @param array<string, string> $errors
     */
    private function invalid(Request $request, array $errors): Response
    {
        if (!$request->headers->has('X-Inertia') && 'json' === $request->getPreferredFormat()) {
            return new JsonResponse(['message' => reset($errors), 'errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('errors', $errors);
        $this->addFlash('error', (string) reset($errors));

        $referer = (string) $request->headers->get('referer', '');
        $path = parse_url($referer, \PHP_URL_PATH);
        $host = parse_url($referer, \PHP_URL_HOST);
        if (\is_string($path) && str_starts_with($path, '/') && (null === $host || $host === $request->getHost())) {
            $query = parse_url($referer, \PHP_URL_QUERY);

            return new RedirectResponse($path.(\is_string($query) ? '?'.$query : ''), Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('escalated.admin.automations.index', [], Response::HTTP_SEE_OTHER);
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

    /** @param array<string, mixed> $payload */
    private function applyPayload(Automation $automation, array $payload): void
    {
        if (isset($payload['name'])) {
            $automation->setName((string) $payload['name']);
        }
        if (array_key_exists('description', $payload)) {
            $automation->setDescription(null === $payload['description'] ? null : (string) $payload['description']);
        }
        if (isset($payload['conditions']) && is_array($payload['conditions'])) {
            $automation->setConditions($payload['conditions']);
        }
        if (isset($payload['actions']) && is_array($payload['actions'])) {
            $automation->setActions($payload['actions']);
        }
        if (array_key_exists('active', $payload)) {
            $automation->setActive((bool) filter_var($payload['active'], \FILTER_VALIDATE_BOOL));
        }
        if (array_key_exists('position', $payload)) {
            $automation->setPosition((int) $payload['position']);
        }
    }

    /** @return array<string, mixed> */
    private function serialize(Automation $a): array
    {
        return [
            'id' => $a->getId(),
            'name' => $a->getName(),
            'description' => $a->getDescription(),
            'conditions' => $a->getConditions(),
            'actions' => $a->getActions(),
            'active' => $a->isActive(),
            'position' => $a->getPosition(),
            'last_run_at' => $a->getLastRunAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $a->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $a->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
