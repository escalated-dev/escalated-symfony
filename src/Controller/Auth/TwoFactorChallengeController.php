<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Auth;

use Escalated\Symfony\EventSubscriber\TwoFactorSubscriber;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Security\AccessFeatures;
use Escalated\Symfony\Security\EnsureAgentVoter;
use Escalated\Symfony\Security\RequiresFeature;
use Escalated\Symfony\Security\TwoFactorSession;
use Escalated\Symfony\Security\UserKeyResolver;
use Escalated\Symfony\Service\TwoFactorService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The second step after the host's password (or SSO) sign-in: renders the
 * shared Auth/TwoFactorChallenge page and checks a TOTP or recovery code
 * (ADR 2026-10-29-configurable-access-features). Hidden (404) while the
 * two-factor switch is off.
 */
#[Route('/two-factor/challenge', name: 'escalated.two-factor.')]
#[RequiresFeature(AccessFeatures::TWO_FACTOR)]
class TwoFactorChallengeController extends AbstractController
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly UiRendererInterface $renderer,
        private readonly TwoFactorService $twoFactor,
        private readonly UserKeyResolver $userKeys,
        #[Autowire(service: 'escalated.two_factor.limiter')]
        private readonly RateLimiterFactory $limiter,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    #[Route('', name: 'challenge', methods: ['GET'])]
    public function show(Request $request): Response
    {
        $userKey = $this->userKey();

        if (null === $this->twoFactor->confirmedFor($userKey) || TwoFactorSession::passed($request->getSession(), $userKey)) {
            return $this->toIntended($request);
        }

        $session = $request->getSession();
        $error = $session instanceof FlashBagAwareSessionInterface ? ($session->getFlashBag()->peek('error')[0] ?? '') : '';

        return $this->renderer->render('Escalated/Auth/TwoFactorChallenge', [
            'action' => $this->generateUrl('escalated.two-factor.verify', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'error' => \is_string($error) ? $error : '',
        ]);
    }

    #[Route('', name: 'verify', methods: ['POST'])]
    public function verify(Request $request): Response
    {
        $userKey = $this->userKey();

        // 5 attempts per minute per user, counted before anything is checked.
        $limit = $this->limiter->create('user-'.$userKey)->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            return new JsonResponse(
                ['message' => 'Too many two-factor attempts. Try again in a minute.'],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => (string) $retryAfter],
            );
        }

        $twoFactor = $this->twoFactor->confirmedFor($userKey);
        if (null === $twoFactor) {
            return $this->toIntended($request);
        }

        $payload = self::payload($request);
        $recoveryCode = $payload['recovery_code'] ?? null;
        $code = $payload['code'] ?? null;
        $usingRecovery = \is_string($recoveryCode) && '' !== trim($recoveryCode);

        if (!$usingRecovery && !(\is_string($code) && '' !== trim($code))) {
            return $this->invalid($request, 'code', 'Enter the code from your authenticator app.');
        }

        $passed = $usingRecovery
            ? \strlen($recoveryCode) <= 64 && $this->twoFactor->useRecoveryCode($twoFactor, $recoveryCode)
            : \strlen((string) $code) <= 16 && $this->twoFactor->verifyAndConsume($twoFactor, (string) $code);

        if (!$passed) {
            $this->logger->notice('Escalated: two-factor challenge failed', ['user_id' => $userKey, 'ip' => $request->getClientIp()]);

            return $usingRecovery
                ? $this->invalid($request, 'recovery_code', 'That recovery code is not valid.')
                : $this->invalid($request, 'code', 'That code is not valid.');
        }

        $session = $request->getSession();
        $session->migrate(true);
        TwoFactorSession::markPassed($session, $userKey);

        return $this->toIntended($request);
    }

    private function userKey(): int|string
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED');

        $user = $this->getUser();
        $key = $user instanceof UserInterface ? $this->userKeys->resolve($user) : null;
        if (null === $key) {
            throw $this->createAccessDeniedException();
        }

        return $key;
    }

    private function toIntended(Request $request): Response
    {
        $target = TwoFactorSession::pullIntended($request->getSession())
            ?? ($this->isGranted(EnsureAgentVoter::ATTRIBUTE)
                ? $this->generateUrl('escalated.agent.dashboard')
                : $this->generateUrl('escalated.admin.reports'));

        if (TwoFactorSubscriber::wantsJson($request)) {
            return new JsonResponse(['redirect' => $target]);
        }

        return new RedirectResponse($target, $request->isMethod('GET') ? Response::HTTP_FOUND : Response::HTTP_SEE_OTHER);
    }

    /**
     * Back to the challenge with the error on the field that was sent (the
     * Inertia convention, flashed under `errors`); a JSON client gets a 422.
     */
    private function invalid(Request $request, string $field, string $message): Response
    {
        if (TwoFactorSubscriber::wantsJson($request)) {
            return new JsonResponse(['message' => $message, 'errors' => [$field => $message]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('errors', [$field => $message]);

        return $this->redirectToRoute('escalated.two-factor.challenge', [], Response::HTTP_SEE_OTHER);
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
