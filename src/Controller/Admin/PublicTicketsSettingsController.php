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
 * Admin settings page for the public-ticket guest policy.
 *
 * Controls the identity assigned to tickets submitted via the public widget
 * or inbound email: either "unassigned" (no requester; Contact row still
 * carries the guest email), "guest_user" (every public ticket owned by a
 * pre-created shared host-app user), or "prompt_signup" (outbound
 * confirmation email embeds a signup invite link).
 *
 * Persists via {@see SettingsService} so admins can switch modes at runtime
 * without a redeploy. Validation follows escalated-laravel's
 * Admin\PublicTicketsSettingsController.
 */
#[Route('/admin/settings/public-tickets', name: 'escalated.admin.settings.public-tickets')]
class PublicTicketsSettingsController extends UiController
{
    public const MODES = ['unassigned', 'guest_user', 'prompt_signup'];

    public function __construct(
        private readonly UiRendererInterface $renderer,
        private readonly SettingsService $settings,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/Settings/PublicTickets', [
            'settings' => $this->loadSettings(),
        ]);
    }

    #[Route('', name: '.update', methods: ['PUT', 'POST'])]
    public function update(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $payload = $this->payload($request);
        $errors = [];

        $mode = $payload['guest_policy_mode'] ?? null;
        if (!\is_string($mode) || '' === $mode) {
            $errors['guest_policy_mode'] = 'The guest policy mode field is required.';
        } elseif (!\in_array($mode, self::MODES, true)) {
            $errors['guest_policy_mode'] = 'The selected guest policy mode is invalid.';
        }

        $userId = $payload['guest_policy_user_id'] ?? null;
        if ('' === $userId) {
            $userId = null;
        }
        if (null !== $userId && (\is_bool($userId) || false === filter_var($userId, \FILTER_VALIDATE_INT) || (int) $userId < 1)) {
            $errors['guest_policy_user_id'] = 'The guest policy user id field must be an integer of at least 1.';
        } elseif (null === $userId && 'guest_user' === $mode) {
            $errors['guest_policy_user_id'] = 'The guest policy user id field is required when guest policy mode is guest_user.';
        }

        $template = $payload['guest_policy_signup_url_template'] ?? null;
        if (null !== $template && !\is_string($template)) {
            $errors['guest_policy_signup_url_template'] = 'The guest policy signup url template field must be a string.';
        } elseif (\is_string($template) && mb_strlen($template) > 500) {
            $errors['guest_policy_signup_url_template'] = 'The guest policy signup url template field must not be greater than 500 characters.';
        }

        if ([] !== $errors) {
            return $this->invalid($request, $errors, 'escalated.admin.settings.public-tickets');
        }

        \assert(\is_string($mode));
        $this->settings->set('guest_policy_mode', $mode);
        // Each mode's own value is kept only while that mode is selected.
        $this->settings->set('guest_policy_user_id', 'guest_user' === $mode ? (string) (int) $userId : '');
        $this->settings->set('guest_policy_signup_url_template', 'prompt_signup' === $mode ? trim((string) $template) : '');

        $this->addFlash('success', 'Guest policy updated.');

        return $this->redirectToRoute('escalated.admin.settings.public-tickets', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * @return array{
     *     guest_policy_mode: string,
     *     guest_policy_user_id: string|null,
     *     guest_policy_signup_url_template: string,
     * }
     */
    private function loadSettings(): array
    {
        $userId = $this->settings->get('guest_policy_user_id', '') ?? '';

        return [
            'guest_policy_mode' => $this->settings->get('guest_policy_mode', 'unassigned') ?? 'unassigned',
            'guest_policy_user_id' => '' !== $userId ? $userId : null,
            'guest_policy_signup_url_template' => $this->settings->get('guest_policy_signup_url_template', '') ?? '',
        ];
    }
}
