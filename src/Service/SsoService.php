<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Escalated\Symfony\Security\SsoException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GuzzleHttp\ClientInterface;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;
use OneLogin\Saml2\AuthnRequest;
use OneLogin\Saml2\Constants as SamlConstants;
use OneLogin\Saml2\Response as SamlResponse;
use OneLogin\Saml2\Settings as SamlSettings;
use OneLogin\Saml2\Utils as SamlUtils;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Single sign-on through the provider an admin picks in Admin > Settings > SSO
 * (ADR 2026-10-29-configurable-access-features), the port of
 * escalated-laravel's SsoService. Crypto is left to maintained libraries:
 * onelogin/php-saml (SAML 2.0, strict mode), league/oauth2-client (OAuth 2 /
 * OIDC with PKCE) and firebase/php-jwt (JWT).
 *
 * Every validate method returns the same identity shape,
 * ['email' => string, 'name' => string, 'role' => string], or throws an
 * SsoException whose message is for the log, never for the user.
 */
class SsoService
{
    /** How long an AuthnRequest ID stays answerable. */
    public const SAML_REQUEST_TTL = 600;

    public const PROVIDERS = ['saml', 'oauth', 'jwt'];

    public const JWT_ALGORITHMS = ['HS256', 'HS384', 'HS512', 'RS256', 'RS384', 'RS512'];

    /** SSO settings keys and their defaults. */
    public const CONFIG_KEYS = [
        'sso_provider' => 'none',
        'sso_entity_id' => '',
        'sso_url' => '',
        'sso_login_url' => '',
        'sso_logout_url' => '',
        'sso_metadata_url' => '',
        'sso_certificate' => '',
        'sso_attr_email' => 'email',
        'sso_attr_name' => 'name',
        'sso_attr_role' => 'role',
        'sso_jwt_secret' => '',
        'sso_jwt_algorithm' => 'HS256',
        'sso_oauth_authorize_url' => '',
        'sso_oauth_token_url' => '',
        'sso_oauth_userinfo_url' => '',
        'sso_oauth_client_id' => '',
        'sso_oauth_client_secret' => '',
        'sso_oauth_scopes' => 'openid profile email',
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly SsoMessageIds $messageIds,
        private readonly UrlGeneratorInterface $urls,
        private readonly RequestStack $requests,
        #[Autowire('%escalated.sso.sp_entity_id%')]
        private readonly ?string $spEntityId = null,
        #[Autowire('%escalated.sso.jwt_audience%')]
        private readonly ?string $jwtAudience = null,
        #[Autowire('%escalated.sso.allow_idp_initiated%')]
        private readonly bool $allowIdpInitiated = false,
        #[Autowire('%escalated.sso.clock_skew%')]
        private readonly int $clockSkew = 120,
        #[Autowire(service: 'escalated.sso.http_client')]
        private readonly ?ClientInterface $httpClient = null,
    ) {
    }

    /**
     * The SSO settings, with the legacy single `sso_url` filling the fields
     * that replaced it.
     *
     * @return array<string, string>
     */
    public function getConfig(): array
    {
        $config = [];
        foreach (self::CONFIG_KEYS as $key => $default) {
            $config[$key] = (string) ($this->settings->get($key, $default) ?? $default);
        }

        $legacyUrl = trim($config['sso_url']);
        if ('' !== $legacyUrl) {
            if ('' === $config['sso_login_url']) {
                $config['sso_login_url'] = $legacyUrl;
            }
            if ('oauth' === $config['sso_provider'] && '' === $config['sso_oauth_authorize_url']) {
                $config['sso_oauth_authorize_url'] = $legacyUrl;
            } elseif ('saml' === $config['sso_provider'] && '' === $config['sso_metadata_url']) {
                $config['sso_metadata_url'] = $legacyUrl;
            }
        }

        return $config;
    }

    /**
     * Save the known SSO keys; anything else in $data is ignored.
     *
     * @param array<string, mixed> $data
     */
    public function saveConfig(array $data): void
    {
        foreach ($data as $key => $value) {
            if (\array_key_exists($key, self::CONFIG_KEYS)) {
                $this->settings->set($key, null === $value ? '' : (string) $value);
            }
        }
    }

    /** The provider chosen in the admin: saml, oauth, jwt or none. */
    public function getProvider(): string
    {
        $provider = (string) $this->settings->get('sso_provider', 'none');

        return \in_array($provider, self::PROVIDERS, true) ? $provider : 'none';
    }

    /**
     * The URLs an admin gives the identity provider.
     *
     * @return array{login: string, sp_entity_id: string, metadata: string, acs: string, oauth_redirect: string, jwt_callback: string}
     */
    public function endpoints(): array
    {
        return [
            'login' => $this->url('escalated.sso.login'),
            'sp_entity_id' => $this->spEntityId(),
            'metadata' => $this->url('escalated.sso.saml.metadata'),
            'acs' => $this->url('escalated.sso.saml.acs'),
            'oauth_redirect' => $this->url('escalated.sso.oauth.callback'),
            'jwt_callback' => $this->url('escalated.sso.jwt.callback'),
        ];
    }

    public function spEntityId(): string
    {
        $configured = trim((string) $this->spEntityId);

        return '' !== $configured ? $configured : $this->url('escalated.sso.saml.metadata');
    }

    // ------------------------------------------------------------------ SAML

    /**
     * onelogin/php-saml settings. Strict: signatures, Issuer, Audience,
     * Destination, Recipient, validity windows and InResponseTo are checked.
     *
     * @throws SsoException
     */
    public function samlSettings(bool $spOnly = false): SamlSettings
    {
        $config = $this->getConfig();
        $certificate = trim($config['sso_certificate']);

        if (!$spOnly && '' === $certificate) {
            throw new SsoException('SAML refused: no IdP certificate is configured.');
        }

        try {
            return new SamlSettings([
                'strict' => true,
                'debug' => false,
                'sp' => [
                    'entityId' => $this->spEntityId(),
                    'assertionConsumerService' => [
                        'url' => $this->url('escalated.sso.saml.acs'),
                        'binding' => SamlConstants::BINDING_HTTP_POST,
                    ],
                    'NameIDFormat' => SamlConstants::NAMEID_UNSPECIFIED,
                ],
                'idp' => [
                    'entityId' => $config['sso_entity_id'],
                    'singleSignOnService' => [
                        'url' => $config['sso_login_url'],
                        'binding' => SamlConstants::BINDING_HTTP_REDIRECT,
                    ],
                    'x509cert' => $certificate,
                ],
                'security' => [
                    // The assertion itself must carry the IdP's signature; a
                    // signed Response around an unsigned assertion is refused.
                    'wantAssertionsSigned' => true,
                    'wantMessagesSigned' => false,
                    'wantNameId' => false,
                    'requestedAuthnContext' => false,
                    'rejectUnsolicitedResponsesWithInResponseTo' => true,
                    'destinationStrictlyMatches' => true,
                    'relaxDestinationValidation' => false,
                    'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
                    'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
                ],
            ], $spOnly);
        } catch (\Throwable $e) {
            throw new SsoException('SAML settings are invalid: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * The IdP sign-in URL for a new AuthnRequest. Its ID is stored server-side
     * (the IdP's cross-site POST back carries no SameSite=Lax session cookie)
     * and must come back as InResponseTo within SAML_REQUEST_TTL.
     *
     * @throws SsoException
     */
    public function samlLoginUrl(): string
    {
        $settings = $this->samlSettings();
        $request = new AuthnRequest($settings);

        $this->messageIds->remember('saml_request', $request->getId(), time() + self::SAML_REQUEST_TTL);

        $url = (string) $settings->getIdPData()['singleSignOnService']['url'];
        if ('' === $url) {
            throw new SsoException('SAML sign-in has no IdP login URL configured.');
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query(['SAMLRequest' => $request->getRequest()]);
    }

    /** @throws SsoException */
    public function samlMetadata(): string
    {
        $settings = $this->samlSettings(true);

        try {
            $metadata = $settings->getSPMetadata();
            $errors = $settings->validateMetadata($metadata);
        } catch (\Throwable $e) {
            throw new SsoException('SAML metadata is invalid: '.$e->getMessage(), 0, $e);
        }

        if ([] !== $errors) {
            throw new SsoException('SAML metadata is invalid: '.implode(', ', $errors));
        }

        return $metadata;
    }

    /**
     * Validate a base64 SAMLResponse posted to the ACS.
     *
     * @return array{email: string, name: string, role: string}
     *
     * @throws SsoException
     */
    public function validateSamlResponse(string $samlResponse): array
    {
        $settings = $this->samlSettings();
        $config = $this->getConfig();

        $xml = base64_decode($samlResponse, true);
        if (false === $xml || '' === $xml) {
            throw new SsoException('SAML refused: the response is not base64.');
        }
        if (str_contains($xml, 'EncryptedAssertion')) {
            throw new SsoException('SAML refused: encrypted assertions are not supported.');
        }

        $requestId = $this->samlInResponseTo($xml);
        if (null === $requestId) {
            if (!$this->allowIdpInitiated) {
                throw new SsoException('SAML refused: unsolicited response (no InResponseTo) and IdP-initiated sign-in is off.');
            }
        } elseif (!$this->messageIds->consume('saml_request', $requestId)) {
            throw new SsoException(sprintf('SAML refused: InResponseTo [%s] is not a pending request.', $requestId));
        }

        /** @var SamlResponse $response */
        $response = $this->atAcsUrl(static function () use ($settings, $samlResponse, $requestId): SamlResponse {
            try {
                $response = new SamlResponse($settings, $samlResponse);
            } catch (\Throwable $e) {
                throw new SsoException('SAML refused: '.$e->getMessage(), 0, $e);
            }

            if (!$response->isValid($requestId)) {
                throw new SsoException('SAML refused: '.$response->getError(false));
            }

            return $response;
        });

        $assertionId = (string) $response->getAssertionId();
        $expires = (int) ($response->getAssertionNotOnOrAfter() ?: time() + 300) + SamlConstants::ALLOWED_CLOCK_DRIFT;
        if ('' === $assertionId || !$this->messageIds->claim('saml_assertion', $assertionId, $expires)) {
            throw new SsoException(sprintf('SAML refused: assertion [%s] was already used.', $assertionId));
        }

        $attributes = $response->getAttributes();
        $first = static fn (string $name): string => isset($attributes[$name][0]) && \is_scalar($attributes[$name][0]) ? trim((string) $attributes[$name][0]) : '';

        $email = $first($config['sso_attr_email']);
        if ('' === $email) {
            $nameId = trim((string) $response->getNameId());
            $email = false !== filter_var($nameId, \FILTER_VALIDATE_EMAIL) ? $nameId : '';
        }

        return $this->identity($email, $first($config['sso_attr_name']), $first($config['sso_attr_role']));
    }

    /**
     * Run a php-saml check as if the request arrived at the configured ACS
     * URL, so Destination and Recipient are compared with that URL and not
     * with whatever host and path a proxy happened to forward.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function atAcsUrl(callable $callback): mixed
    {
        $acs = parse_url($this->url('escalated.sso.saml.acs'));
        $scheme = $acs['scheme'] ?? 'https';
        $savedUri = $_SERVER['REQUEST_URI'] ?? null;
        $savedQuery = $_SERVER['QUERY_STRING'] ?? null;

        SamlUtils::setSelfProtocol($scheme);
        SamlUtils::setSelfHost($acs['host'] ?? 'localhost');
        SamlUtils::setSelfPort((string) ($acs['port'] ?? ('https' === $scheme ? 443 : 80)));
        SamlUtils::setBaseURLPath(null);
        $_SERVER['REQUEST_URI'] = $acs['path'] ?? '/';
        unset($_SERVER['QUERY_STRING']);

        try {
            return $callback();
        } finally {
            SamlUtils::setSelfProtocol(null);
            SamlUtils::setSelfHost(null);
            SamlUtils::setSelfPort(null);
            if (null === $savedUri) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $savedUri;
            }
            if (null !== $savedQuery) {
                $_SERVER['QUERY_STRING'] = $savedQuery;
            }
        }
    }

    private function samlInResponseTo(string $xml): ?string
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml, \LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || null === $doc->documentElement) {
            throw new SsoException('SAML refused: malformed XML.');
        }

        $value = trim($doc->documentElement->getAttribute('InResponseTo'));

        return '' === $value ? null : $value;
    }

    // ----------------------------------------------------------------- OAuth

    public function oauthProvider(): GenericProvider
    {
        $config = $this->getConfig();
        $scopes = preg_split('/[\s,]+/', trim($config['sso_oauth_scopes'])) ?: [];

        $collaborators = [];
        if (null !== $this->httpClient) {
            $collaborators['httpClient'] = $this->httpClient;
        }

        return new GenericProvider([
            'clientId' => $config['sso_oauth_client_id'],
            'clientSecret' => $config['sso_oauth_client_secret'],
            'redirectUri' => $this->url('escalated.sso.oauth.callback'),
            'urlAuthorize' => $config['sso_oauth_authorize_url'],
            'urlAccessToken' => $config['sso_oauth_token_url'],
            'urlResourceOwnerDetails' => $config['sso_oauth_userinfo_url'],
            'scopes' => array_values(array_filter($scopes)),
            'scopeSeparator' => ' ',
            'pkceMethod' => GenericProvider::PKCE_METHOD_S256,
        ], $collaborators);
    }

    /**
     * Exchange the authorization code (with the PKCE verifier) server to
     * server and read the profile from the userinfo endpoint.
     *
     * @return array{email: string, name: string, role: string}
     *
     * @throws SsoException
     */
    public function oauthIdentity(string $code, string $pkceVerifier): array
    {
        $config = $this->getConfig();
        $provider = $this->oauthProvider();
        $provider->setPkceCode($pkceVerifier);

        try {
            $token = $provider->getAccessToken('authorization_code', ['code' => $code]);
            \assert($token instanceof AccessToken);
            $profile = $provider->getResourceOwner($token)->toArray();
        } catch (\Throwable $e) {
            throw new SsoException('OAuth refused: '.$e->getMessage(), 0, $e);
        }

        if (\array_key_exists('email_verified', $profile) && \in_array($profile['email_verified'], [false, 'false', 0, '0'], true)) {
            throw new SsoException('OAuth refused: the provider says the email is not verified.');
        }

        $claim = static function (string $name, string $fallback = '') use ($profile): string {
            $value = self::dig($profile, $name) ?? ('' !== $fallback ? self::dig($profile, $fallback) : null);

            return \is_scalar($value) ? trim((string) $value) : '';
        };

        return $this->identity(
            $claim($config['sso_attr_email'], 'email'),
            $claim($config['sso_attr_name'], 'name'),
            $claim($config['sso_attr_role']),
        );
    }

    /**
     * A claim by dotted path (`profile.email`), as Laravel's data_get().
     *
     * @param array<mixed> $data
     */
    private static function dig(array $data, string $path): mixed
    {
        if ('' === $path) {
            return null;
        }
        if (\array_key_exists($path, $data)) {
            return $data[$path];
        }

        foreach (explode('.', $path) as $segment) {
            if (!\is_array($data) || !\array_key_exists($segment, $data)) {
                return null;
            }
            $data = $data[$segment];
        }

        return $data;
    }

    // ------------------------------------------------------------------- JWT

    /**
     * Validate a JWT handed back by the identity provider.
     *
     * @return array{email: string, name: string, role: string}
     *
     * @throws SsoException
     */
    public function validateJwtToken(string $token): array
    {
        $config = $this->getConfig();
        $algorithm = '' !== $config['sso_jwt_algorithm'] ? $config['sso_jwt_algorithm'] : 'HS256';
        $secret = $config['sso_jwt_secret'];

        if (!\in_array($algorithm, self::JWT_ALGORITHMS, true)) {
            throw new SsoException(sprintf('JWT refused: unsupported algorithm %s.', $algorithm));
        }
        if ('' === $secret) {
            throw new SsoException('JWT refused: no secret or public key is configured.');
        }
        if ('' === $token) {
            throw new SsoException('JWT refused: no token.');
        }

        $previousLeeway = JWT::$leeway;
        JWT::$leeway = $this->clockSkew();

        try {
            // The Key pins the algorithm: a token naming any other alg
            // (including "none") is refused by the library.
            $claims = (array) JWT::decode($token, new Key($secret, $algorithm));
        } catch (\Throwable $e) {
            throw new SsoException('JWT refused: '.$e->getMessage(), 0, $e);
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        if (!isset($claims['exp']) || !is_numeric($claims['exp'])) {
            throw new SsoException('JWT refused: no exp claim.');
        }

        $audience = $this->jwtAudience();
        $tokenAudiences = array_map(static fn (mixed $a): string => \is_scalar($a) ? (string) $a : '', (array) ($claims['aud'] ?? []));
        if ('' === $audience || !\in_array($audience, $tokenAudiences, true)) {
            throw new SsoException('JWT refused: aud does not name this application.');
        }

        $jti = isset($claims['jti']) && \is_scalar($claims['jti']) ? (string) $claims['jti'] : '';
        if ('' === $jti) {
            throw new SsoException('JWT refused: no jti claim.');
        }
        if (!$this->messageIds->claim('jwt', $jti, (int) $claims['exp'] + $this->clockSkew())) {
            throw new SsoException(sprintf('JWT refused: jti [%s] was already used.', $jti));
        }

        $claim = static function (string $name, string $fallback = '') use ($claims): string {
            $value = $claims[$name] ?? ('' !== $fallback ? ($claims[$fallback] ?? null) : null);

            return \is_scalar($value) ? trim((string) $value) : '';
        };

        return $this->identity(
            $claim($config['sso_attr_email'], 'email'),
            $claim($config['sso_attr_name'], 'name'),
            $claim($config['sso_attr_role']),
        );
    }

    /** The `aud` a JWT must carry: configured, else this request's scheme and host. */
    public function jwtAudience(): string
    {
        $configured = trim((string) $this->jwtAudience);
        if ('' !== $configured) {
            return $configured;
        }

        $request = $this->requests->getMainRequest();

        return null !== $request ? $request->getSchemeAndHttpHost() : '';
    }

    public function clockSkew(): int
    {
        return max(0, min(300, $this->clockSkew));
    }

    /**
     * @return array{email: string, name: string, role: string}
     *
     * @throws SsoException
     */
    private function identity(string $email, string $name, string $role): array
    {
        if (false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new SsoException('SSO refused: no valid email in the identity.');
        }

        return ['email' => $email, 'name' => $name, 'role' => $role];
    }

    private function url(string $route): string
    {
        return $this->urls->generate($route, [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
