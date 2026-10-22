<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Escalated\Symfony\Controller\UiController;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Service\DataRetentionService;
use Escalated\Symfony\Service\SettingsService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Escalated/Admin/Settings/DataRetention, as escalated-laravel's
 * Admin\DataRetentionController. The settings are applied by the
 * `escalated:purge-expired` console command.
 */
#[Route('/admin/settings/data-retention', name: 'escalated.admin.settings.data-retention')]
class DataRetentionController extends UiController
{
    public function __construct(
        private readonly UiRendererInterface $renderer,
        private readonly SettingsService $settings,
        private readonly DataRetentionService $retention,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/Settings/DataRetention', [
            'settings' => $this->retention->settings(),
            'purgePreview' => $this->retention->preview(),
        ]);
    }

    #[Route('', name: '.update', methods: ['POST', 'PUT'])]
    public function update(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $payload = $this->payload($request);
        $errors = [];

        $choices = [
            'retention_closed_tickets' => DataRetentionService::TICKET_CHOICES,
            'retention_attachments' => DataRetentionService::ATTACHMENT_CHOICES,
            'retention_audit_logs' => DataRetentionService::AUDIT_LOG_CHOICES,
        ];
        foreach ($choices as $key => $allowed) {
            $value = $payload[$key] ?? null;
            if (null === $value || '' === $value) {
                $errors[$key] = sprintf('The %s field is required.', str_replace('_', ' ', $key));
            } elseif (!\is_string($value) || !\in_array($value, $allowed, true)) {
                $errors[$key] = sprintf('The selected %s is invalid.', str_replace('_', ' ', $key));
            }
        }

        $gdpr = $payload['retention_user_data_gdpr'] ?? null;
        if (null === $gdpr || '' === $gdpr) {
            $errors['retention_user_data_gdpr'] = 'The retention user data gdpr field is required.';
        } elseif (!\in_array($gdpr, [true, false, 0, 1, '0', '1'], true)) {
            $errors['retention_user_data_gdpr'] = 'The retention user data gdpr field must be true or false.';
        }

        if ([] !== $errors) {
            return $this->invalid($request, $errors, 'escalated.admin.settings.data-retention');
        }

        foreach (array_keys($choices) as $key) {
            $this->settings->set($key, (string) $payload[$key]);
        }
        $this->settings->set('retention_user_data_gdpr', \in_array($gdpr, [true, 1, '1'], true) ? '1' : '0');

        $this->addFlash('success', 'Data retention settings updated.');

        return $this->redirectToRoute('escalated.admin.settings.data-retention', [], Response::HTTP_SEE_OTHER);
    }
}
