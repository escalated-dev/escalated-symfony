<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Escalated\Symfony\Controller\UiController;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Service\ConnectionInspector;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Escalated/Admin/Settings/DatabaseConnection, read-only.
 *
 * escalated-laravel lets an admin switch the connection from this screen. Here
 * the connection is the one behind the `entity_manager` bundle option, which is
 * configuration, so the page shows every Doctrine connection and which one
 * Escalated uses, with `pinnedByConfig` set: the page then renders without the
 * switch, and a save is refused.
 */
#[Route('/admin/settings/database', name: 'escalated.admin.settings.database')]
class DatabaseConnectionController extends UiController
{
    public const PINNED_MESSAGE = 'The database connection is set in configuration and cannot be changed from here.';

    public function __construct(
        private readonly UiRendererInterface $renderer,
        private readonly ConnectionInspector $inspector,
        private readonly string $tablePrefix,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/Settings/DatabaseConnection', [
            'connections' => $this->inspector->all(),
            'current' => $this->inspector->current(),
            'hostDefault' => $this->inspector->hostDefault(),
            'pinnedByConfig' => true,
            'tablePrefix' => $this->tablePrefix,
        ]);
    }

    /**
     * Probe one connection without changing anything.
     */
    #[Route('/test', name: '.test', methods: ['POST'])]
    public function test(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $connection = $this->payload($request)['connection'] ?? null;
        if (!\is_string($connection) || '' === trim($connection)) {
            return $this->invalid($request, ['connection' => 'The connection field is required.'], 'escalated.admin.settings.database');
        }
        if (mb_strlen($connection) > 64) {
            return $this->invalid($request, ['connection' => 'The connection field must not be greater than 64 characters.'], 'escalated.admin.settings.database');
        }
        if (!$this->inspector->has($connection)) {
            return $this->invalid($request, ['connection' => sprintf('[%s] is not a configured Doctrine connection.', $connection)], 'escalated.admin.settings.database');
        }

        return new JsonResponse($this->inspector->describe($connection));
    }

    #[Route('', name: '.update', methods: ['POST', 'PUT'])]
    public function update(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $this->addFlash('error', self::PINNED_MESSAGE);

        return $this->back($request, 'escalated.admin.settings.database');
    }
}
