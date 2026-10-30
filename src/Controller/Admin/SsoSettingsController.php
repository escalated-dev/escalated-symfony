<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Escalated\Symfony\Controller\UiController;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Security\AccessFeatures;
use Escalated\Symfony\Security\EnsureAdminVoter;
use Escalated\Symfony\Security\RequiresFeature;
use Escalated\Symfony\Service\SsoService;
use Escalated\Symfony\Support\SecretMask;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Escalated/Admin/Settings/SsoSettings, as escalated-laravel's
 * Admin\SsoSettingsController: the SSO provider and its fields, kept in the
 * settings store, and the read-only `endpoints` the admin gives the IdP.
 * Stored secrets reach the page only as a mask. Hidden (404) while the SSO
 * switch is off.
 */
#[Route('/admin/settings/sso', name: 'escalated.admin.settings.sso')]
#[RequiresFeature(AccessFeatures::SSO)]
class SsoSettingsController extends UiController
{
    /** Stored secrets the page sees only as a mask. */
    public const SECRET_KEYS = ['sso_jwt_secret', 'sso_oauth_client_secret'];

    /** Field => [kind, max length]. */
    private const FIELDS = [
        'sso_entity_id' => ['string', 500],
        'sso_url' => ['url', 500],
        'sso_login_url' => ['url', 500],
        'sso_logout_url' => ['url', 500],
        'sso_metadata_url' => ['url', 500],
        'sso_certificate' => ['string', 10000],
        'sso_attr_email' => ['string', 100],
        'sso_attr_name' => ['string', 100],
        'sso_attr_role' => ['string', 100],
        'sso_jwt_secret' => ['string', 10000],
        'sso_jwt_algorithm' => ['string', 10],
        'sso_oauth_authorize_url' => ['url', 500],
        'sso_oauth_token_url' => ['url', 500],
        'sso_oauth_userinfo_url' => ['url', 500],
        'sso_oauth_client_id' => ['string', 255],
        'sso_oauth_client_secret' => ['string', 500],
        'sso_oauth_scopes' => ['string', 255],
    ];

    /** Provider => the fields it needs. */
    private const REQUIRED = [
        'saml' => ['sso_entity_id', 'sso_login_url'],
        'jwt' => ['sso_url', 'sso_jwt_secret'],
        'oauth' => ['sso_oauth_authorize_url', 'sso_oauth_token_url', 'sso_oauth_userinfo_url', 'sso_oauth_client_id'],
        'none' => [],
    ];

    public function __construct(
        private readonly UiRendererInterface $renderer,
        private readonly SsoService $sso,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted(EnsureAdminVoter::ATTRIBUTE);

        $settings = $this->sso->getConfig();
        foreach (self::SECRET_KEYS as $key) {
            $settings[$key] = SecretMask::mask($settings[$key] ?? null);
        }

        return $this->renderer->render('Escalated/Admin/Settings/SsoSettings', [
            'settings' => $settings,
            // What the admin gives the identity provider.
            'endpoints' => $this->sso->endpoints(),
        ]);
    }

    #[Route('', name: '.update', methods: ['POST', 'PUT'])]
    public function update(Request $request): Response
    {
        $this->denyAccessUnlessGranted(EnsureAdminVoter::ATTRIBUTE);

        $payload = $this->payload($request);
        $errors = [];

        $provider = $payload['sso_provider'] ?? null;
        if (!\is_string($provider) || '' === $provider) {
            $errors['sso_provider'] = 'The sso provider field is required.';
        } elseif (!\array_key_exists($provider, self::REQUIRED)) {
            $errors['sso_provider'] = 'The selected sso provider is invalid.';
        }

        $values = ['sso_provider' => \is_string($provider) ? $provider : 'none'];
        foreach (self::FIELDS as $field => [$kind, $max]) {
            if (!\array_key_exists($field, $payload) || null === $payload[$field]) {
                continue;
            }

            $value = $payload[$field];
            $label = str_replace('_', ' ', $field);
            if (!\is_string($value)) {
                $errors[$field] = sprintf('The %s field must be a string.', $label);
                continue;
            }
            if (mb_strlen($value) > $max) {
                $errors[$field] = sprintf('The %s field must not be greater than %d characters.', $label, $max);
                continue;
            }
            if ('url' === $kind && '' !== $value && !self::isUrl($value)) {
                $errors[$field] = sprintf('The %s field must be a valid URL.', $label);
                continue;
            }
            $values[$field] = $value;
        }

        if (isset($values['sso_jwt_algorithm']) && '' !== $values['sso_jwt_algorithm'] && !\in_array($values['sso_jwt_algorithm'], SsoService::JWT_ALGORITHMS, true)) {
            $errors['sso_jwt_algorithm'] = 'The selected sso jwt algorithm is invalid.';
        }

        // A masked secret posted back means "keep the stored one".
        $stored = $this->sso->getConfig();
        foreach (self::SECRET_KEYS as $key) {
            if (SecretMask::isMasked($values[$key] ?? null)) {
                unset($values[$key]);
            }
        }

        foreach (self::REQUIRED[$values['sso_provider']] ?? [] as $field) {
            $value = $values[$field] ?? (\in_array($field, self::SECRET_KEYS, true) ? $stored[$field] : '');
            if (!isset($errors[$field]) && '' === trim((string) $value)) {
                $errors[$field] = sprintf('The %s field is required.', str_replace('_', ' ', $field));
            }
        }

        if ([] !== $errors) {
            return $this->invalid($request, $errors, 'escalated.admin.settings.sso');
        }

        $this->sso->saveConfig($values);
        $this->addFlash('success', 'SSO settings updated.');

        return $this->back($request, 'escalated.admin.settings.sso');
    }

    private static function isUrl(string $value): bool
    {
        if (false === filter_var($value, \FILTER_VALIDATE_URL)) {
            return false;
        }

        return \in_array(strtolower((string) parse_url($value, \PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}
