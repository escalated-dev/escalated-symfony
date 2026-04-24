<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Service\SettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
 * without a redeploy.
 */
#[Route('/admin/settings/public-tickets', name: 'escalated.admin.settings.public-tickets')]
class PublicTicketsSettingsController extends AbstractController
{
    public function __construct(
        private readonly UiRendererInterface $renderer,
        private readonly SettingsService $settings,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/Settings/PublicTickets', [
            'settings' => $this->loadSettings(),
        ]);
    }

    #[Route('', name: '.update', methods: ['PUT', 'POST'])]
    public function update(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $mode = (string) $request->request->get('guest_policy_mode', 'unassigned');
        if (! in_array($mode, ['unassigned', 'guest_user', 'prompt_signup'], true)) {
            $mode = 'unassigned';
        }

        $this->settings->set('guest_policy_mode', $mode);

        if ($mode === 'guest_user') {
            $userId = (int) $request->request->get('guest_policy_user_id', 0);
            $this->settings->set('guest_policy_user_id', $userId > 0 ? (string) $userId : '');
        } else {
            $this->settings->set('guest_policy_user_id', '');
        }

        if ($mode === 'prompt_signup') {
            $template = substr(
                (string) $request->request->get('guest_policy_signup_url_template', ''),
                0,
                500
            );
            $this->settings->set('guest_policy_signup_url_template', $template);
        } else {
            $this->settings->set('guest_policy_signup_url_template', '');
        }

        $this->addFlash('success', 'Guest policy updated.');

        return $this->redirectToRoute('escalated.admin.settings.public-tickets');
    }

    /**
     * @return array{
     *     guest_policy_mode: string,
     *     guest_policy_user_id: int|null,
     *     guest_policy_signup_url_template: string,
     * }
     */
    private function loadSettings(): array
    {
        $userIdRaw = $this->settings->get('guest_policy_user_id', '');

        return [
            'guest_policy_mode' => $this->settings->get('guest_policy_mode', 'unassigned') ?? 'unassigned',
            'guest_policy_user_id' => $userIdRaw !== '' && is_numeric($userIdRaw) ? (int) $userIdRaw : null,
            'guest_policy_signup_url_template' => $this->settings->get(
                'guest_policy_signup_url_template',
                ''
            ) ?? '',
        ];
    }
}
