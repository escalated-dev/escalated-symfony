<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Service\SettingsService;
use Escalated\Symfony\Widget\WidgetOrigins;
use Escalated\Symfony\Widget\WidgetSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/settings', name: 'escalated.admin.settings.')]
class SettingsController extends AbstractController
{
    /**
     * Settings this page can save. Everything else on the shared settings
     * page comes from the bundle configuration and is hidden.
     */
    private const SUPPORTED = [WidgetOrigins::SETTING];

    public function __construct(
        private readonly UiRendererInterface $renderer,
        private readonly SettingsService $settings,
        private readonly WidgetSettings $widgetSettings,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/Settings', [
            'settings' => [
                // Read-only here: the widget is switched on in the
                // WidgetSettings service configuration.
                'widget_enabled' => $this->widgetSettings->isEnabled(),
                WidgetOrigins::SETTING => $this->settings->get(WidgetOrigins::SETTING, '') ?? '',
            ],
            'supported_settings' => self::SUPPORTED,
            'update_url' => $this->generateUrl('escalated.admin.settings.update'),
        ]);
    }

    #[Route('', name: 'update', methods: ['POST', 'PUT'])]
    public function update(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $payload = $this->payload($request);

        if (\array_key_exists(WidgetOrigins::SETTING, $payload)) {
            $value = $payload[WidgetOrigins::SETTING];
            if (null !== $value && !\is_string($value)) {
                $this->addFlash('error', 'Sites that can embed the widget must be text, one origin per line.');

                return $this->redirectToRoute('escalated.admin.settings.index');
            }

            $value = trim((string) $value);
            $invalid = WidgetOrigins::invalidEntries($value);
            if ([] !== $invalid) {
                $this->addFlash('error', sprintf(
                    'Not a site origin (use scheme://host[:port], no path; "*" alone is not allowed): %s',
                    implode(', ', $invalid),
                ));

                return $this->redirectToRoute('escalated.admin.settings.index');
            }

            $this->settings->set(WidgetOrigins::SETTING, $value);
        }

        $this->addFlash('success', 'Settings updated.');

        return $this->redirectToRoute('escalated.admin.settings.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        if ('' !== ($json = (string) $request->getContent())
            && str_starts_with((string) $request->headers->get('Content-Type'), 'application/json')
        ) {
            $decoded = json_decode($json, true);
            if (\is_array($decoded)) {
                return $decoded;
            }
        }

        return $request->request->all();
    }
}
