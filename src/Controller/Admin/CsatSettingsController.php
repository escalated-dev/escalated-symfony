<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Escalated\Symfony\Controller\UiController;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Service\SettingsService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Escalated/Admin/Settings/CsatSettings, as escalated-laravel's
 * Admin\CsatSettingsController: the satisfaction survey's question, scale and
 * delivery, kept in the settings store.
 */
#[Route('/admin/settings/csat', name: 'escalated.admin.settings.csat')]
class CsatSettingsController extends UiController
{
    public const SCALES = ['1-3', '1-5'];
    public const TRIGGERS = ['on_resolve', 'delayed', 'manual'];
    public const DEFAULT_QUESTION = 'How would you rate your support experience?';

    public function __construct(
        private readonly UiRendererInterface $renderer,
        private readonly SettingsService $settings,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/Settings/CsatSettings', [
            'settings' => [
                'csat_question_text' => $this->settings->get('csat_question_text', self::DEFAULT_QUESTION) ?? self::DEFAULT_QUESTION,
                'csat_scale' => $this->settings->get('csat_scale', '1-5') ?? '1-5',
                'csat_delivery_trigger' => $this->settings->get('csat_delivery_trigger', 'on_resolve') ?? 'on_resolve',
                'csat_delay_hours' => $this->settings->getInt('csat_delay_hours', 0),
            ],
        ]);
    }

    #[Route('', name: '.update', methods: ['POST', 'PUT'])]
    public function update(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $payload = $this->payload($request);
        $errors = [];

        $question = $payload['csat_question_text'] ?? null;
        if (!\is_string($question) || '' === trim($question)) {
            $errors['csat_question_text'] = 'The csat question text field is required.';
        } elseif (mb_strlen($question) > 500) {
            $errors['csat_question_text'] = 'The csat question text field must not be greater than 500 characters.';
        }

        $scale = $payload['csat_scale'] ?? null;
        if (!\is_string($scale) || '' === $scale) {
            $errors['csat_scale'] = 'The csat scale field is required.';
        } elseif (!\in_array($scale, self::SCALES, true)) {
            $errors['csat_scale'] = 'The selected csat scale is invalid.';
        }

        $trigger = $payload['csat_delivery_trigger'] ?? null;
        if (!\is_string($trigger) || '' === $trigger) {
            $errors['csat_delivery_trigger'] = 'The csat delivery trigger field is required.';
        } elseif (!\in_array($trigger, self::TRIGGERS, true)) {
            $errors['csat_delivery_trigger'] = 'The selected csat delivery trigger is invalid.';
        }

        $delay = $payload['csat_delay_hours'] ?? null;
        if (null === $delay || '' === $delay) {
            $errors['csat_delay_hours'] = 'The csat delay hours field is required.';
        } elseif (\is_bool($delay) || false === filter_var($delay, \FILTER_VALIDATE_INT)) {
            $errors['csat_delay_hours'] = 'The csat delay hours field must be an integer.';
        } elseif ((int) $delay < 0 || (int) $delay > 168) {
            $errors['csat_delay_hours'] = 'The csat delay hours field must be between 0 and 168.';
        }

        if ([] !== $errors) {
            return $this->invalid($request, $errors, 'escalated.admin.settings.csat');
        }

        \assert(\is_string($question) && \is_string($scale) && \is_string($trigger));
        $this->settings->set('csat_question_text', $question);
        $this->settings->set('csat_scale', $scale);
        $this->settings->set('csat_delivery_trigger', $trigger);
        $this->settings->set('csat_delay_hours', (string) (int) $delay);

        $this->addFlash('success', 'CSAT settings updated.');

        return $this->redirectToRoute('escalated.admin.settings.csat', [], Response::HTTP_SEE_OTHER);
    }
}
