<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Security\AccessFeatures;
use Escalated\Symfony\Security\EnsureAdminVoter;
use Escalated\Symfony\Security\EnsureAgentVoter;
use Escalated\Symfony\Security\RequiresFeature;
use Escalated\Symfony\Security\TwoFactorSession;
use Escalated\Symfony\Security\UserKeyResolver;
use Escalated\Symfony\Service\TwoFactorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A staff user's own two-factor enrolment (Admin/Settings/TwoFactor), open to
 * every agent and admin, not only admins. Hidden (404) while the two-factor
 * switch is off.
 */
#[Route('/admin/settings/two-factor', name: 'escalated.admin.two-factor.')]
#[RequiresFeature(AccessFeatures::TWO_FACTOR)]
class TwoFactorController extends AbstractController
{
    public function __construct(
        private readonly UiRendererInterface $renderer,
        private readonly TwoFactorService $twoFactor,
        private readonly UserKeyResolver $userKeys,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $userKey = $this->staffKey();

        return $this->renderer->render('Escalated/Admin/Settings/TwoFactor', [
            'enabled' => null !== $this->twoFactor->confirmedFor($userKey),
            'pending' => null === $this->twoFactor->confirmedFor($userKey) && null !== $this->twoFactor->pendingFor($userKey),
            'required' => $this->twoFactor->required(),
        ]);
    }

    #[Route('/setup', name: 'setup', methods: ['POST'])]
    public function setup(): Response
    {
        $userKey = $this->staffKey();

        if (null !== $this->twoFactor->confirmedFor($userKey)) {
            $this->addFlash('error', 'Two-factor authentication is already enabled. Disable it first to set it up again.');

            return $this->back();
        }

        $enrolment = $this->twoFactor->begin($userKey);
        $this->addFlash('two_factor_setup', [
            'qr_uri' => $this->twoFactor->qrUri($enrolment['secret'], $this->accountName()),
            'recovery_codes' => $enrolment['recovery_codes'],
        ]);

        return $this->back();
    }

    #[Route('/confirm', name: 'confirm', methods: ['POST'])]
    public function confirm(Request $request): Response
    {
        $userKey = $this->staffKey();
        $code = self::payload($request)['code'] ?? null;

        if (!\is_string($code) || 1 !== preg_match('/^\d{6}$/', trim($code))) {
            return $this->invalid('The code must be the six digits your authenticator app shows.');
        }

        $pending = $this->twoFactor->pendingFor($userKey);
        if (null === $pending || null !== $this->twoFactor->confirmedFor($userKey)) {
            return $this->invalid('No pending two-factor setup found.');
        }

        if (!$this->twoFactor->verifyAndConsume($pending, $code)) {
            return $this->invalid('Invalid verification code.');
        }

        $this->twoFactor->confirm($pending);

        // The code just entered proves the second factor for this session.
        TwoFactorSession::markPassed($request->getSession(), $userKey);

        $this->addFlash('success', 'Two-factor authentication enabled.');
        $this->addFlash('two_factor_confirmed', ['recovery_codes' => $this->twoFactor->recoveryCodesOf($pending)]);

        return $this->back();
    }

    #[Route('/disable', name: 'disable', methods: ['POST'])]
    public function disable(): Response
    {
        $this->twoFactor->remove($this->staffKey());
        $this->addFlash('success', 'Two-factor authentication disabled.');

        return $this->back();
    }

    private function staffKey(): int|string
    {
        if (!$this->isGranted(EnsureAgentVoter::ATTRIBUTE) && !$this->isGranted(EnsureAdminVoter::ATTRIBUTE)) {
            throw $this->createAccessDeniedException();
        }

        $user = $this->getUser();
        $key = $user instanceof UserInterface ? $this->userKeys->resolve($user) : null;
        if (null === $key) {
            throw new AccessDeniedHttpException('Two-factor enrolment needs a signed-in user.');
        }

        return $key;
    }

    /** The account name authenticator apps show: the email when the user has one. */
    private function accountName(): string
    {
        $user = $this->getUser();
        if (null !== $user && method_exists($user, 'getEmail')) {
            $email = $user->getEmail();
            if (\is_string($email) && '' !== $email) {
                return $email;
            }
        }

        return (string) $user?->getUserIdentifier();
    }

    private function invalid(string $message): Response
    {
        $this->addFlash('errors', ['code' => $message]);
        $this->addFlash('error', $message);

        return $this->back();
    }

    private function back(): Response
    {
        return $this->redirectToRoute('escalated.admin.two-factor.index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(Request $request): array
    {
        $content = (string) $request->getContent();
        if ('' !== $content && str_contains((string) $request->headers->get('Content-Type'), 'json')) {
            $decoded = json_decode($content, true);

            return \is_array($decoded) ? $decoded : [];
        }

        return $request->request->all();
    }
}
