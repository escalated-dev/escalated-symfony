<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Security\AccessFeatures;
use Escalated\Symfony\Service\SettingsService;
use Escalated\Symfony\Service\TwoFactorService;
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
        private readonly AccessFeatures $features = new AccessFeatures(),
        private readonly ?TwoFactorService $twoFactor = null,
    ) {
    }

    /**
     * @return list<string>
     */
    private function supported(): array
    {
        // `two_factor_required` (Admin/Settings/TwoFactor saves it on its own)
        // only while two-factor is switched on.
        return $this->features->twoFactor()
            ? [...self::SUPPORTED, TwoFactorService::REQUIRED_SETTING]
            : self::SUPPORTED;
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $settings = [
            // Read-only here: the widget is switched on in the
            // WidgetSettings service configuration.
            'widget_enabled' => $this->widgetSettings->isEnabled(),
            WidgetOrigins::SETTING => $this->settings->get(WidgetOrigins::SETTING, '') ?? '',
        ];
        if ($this->features->twoFactor() && null !== $this->twoFactor) {
            $settings[TwoFactorService::REQUIRED_SETTING] = $this->twoFactor->required();
        }

        return $this->renderer->render('Escalated/Admin/Settings', [
            'settings' => $settings,
            'supported_settings' => $this->supported(),
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

        if ($this->features->twoFactor() && \array_key_exists(TwoFactorService::REQUIRED_SETTING, $payload)) {
            $required = $payload[TwoFactorService::REQUIRED_SETTING];
            $required = \is_bool($required) ? $required : (\is_scalar($required) ? filter_var($required, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) : null);
            if (null === $required) {
                $this->addFlash('errors', [TwoFactorService::REQUIRED_SETTING => 'Requiring two-factor must be true or false.']);
                $this->addFlash('error', 'Requiring two-factor must be true or false.');

                return $this->redirectToRoute('escalated.admin.settings.index');
            }

            $this->settings->set(TwoFactorService::REQUIRED_SETTING, $required ? '1' : '0');
        }

        $this->addFlash('success', 'Settings updated.');

        // The two-factor page saves `two_factor_required` from its own screen.
        if ([TwoFactorService::REQUIRED_SETTING] === array_keys($payload) && $this->features->twoFactor()) {
            return $this->redirectToRoute('escalated.admin.two-factor.index');
        }

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
