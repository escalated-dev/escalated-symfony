<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Access;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\Role;
use Escalated\Symfony\Security\PermissionSeeder;
use Escalated\Symfony\Security\UserPermissions;
use Escalated\Symfony\Service\SettingsService;
use Escalated\Symfony\Service\TwoFactorService;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Escalated\Symfony\Tests\Kernel\Fixtures\SamlIdp;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as PsrResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Single sign-on behind the install-level switch
 * (ADR 2026-10-29-configurable-access-features, "Port test checklist":
 * Switches and SSO), through the real HTTP stack: settings in the database,
 * SAML responses signed at runtime by a throwaway IdP, OAuth against a mocked
 * HTTP client, JWTs from firebase/php-jwt.
 */
final class SsoTest extends EscalatedWebTestCase
{
    private const JWT_SECRET = 'a-test-secret-that-is-long-enough-for-hs256-0123456789';
    private const ACS = 'http://localhost/support/sso/saml/acs';
    private const METADATA = 'http://localhost/support/sso/saml/metadata';
    private const DASHBOARD = '/support/agent/dashboard';
    private const SETTINGS = '/support/admin/settings/sso';

    private KernelBrowser $client;
    private TestUser $admin;
    private TestUser $person;

    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    // ---- Switch off (the default) -----------------------------------------

    public function testWithTheSwitchOffEverySsoRouteAnswers404(): void
    {
        $this->boot(['access' => []]);
        $this->settings()->set('sso_provider', 'jwt');
        $this->client->loginUser($this->admin);

        $this->visit('/support/admin/reports');
        self::assertFalse($this->shared()['features']['sso']);

        $this->client->request('GET', self::SETTINGS);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', self::SETTINGS, ['sso_provider' => 'none']);
        self::assertResponseStatusCodeSame(404);

        $this->client->getCookieJar()->clear();
        foreach ([['GET', '/support/sso/login'], ['GET', '/support/sso/saml/metadata'], ['POST', '/support/sso/saml/acs'], ['GET', '/support/sso/oauth/callback'], ['GET', '/support/sso/jwt/callback?jwt='.$this->jwt()], ['POST', '/support/sso/jwt/callback']] as [$method, $uri]) {
            $this->client->request($method, $uri);
            self::assertResponseStatusCodeSame(404, $method.' '.$uri);
        }
        self::assertSame('jwt', $this->settings()->get('sso_provider'), 'stored settings are kept');
    }

    public function testTheSwitchIsNotASetting(): void
    {
        $this->boot(['access' => []]);
        $this->client->loginUser($this->admin);

        $this->client->request('POST', '/support/admin/settings', ['sso' => '1', 'access.sso' => '1', 'ESCALATED_SSO' => 'true']);
        self::assertLessThan(500, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', self::SETTINGS);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheSwitchReadsTheEnvVarWhenNotConfigured(): void
    {
        $_SERVER['ESCALATED_SSO'] = $_ENV['ESCALATED_SSO'] = 'true';
        putenv('ESCALATED_SSO=true');
        try {
            $this->boot(['access' => []]);
            $this->client->loginUser($this->admin);

            $page = $this->visit(self::SETTINGS);
            self::assertSame('Escalated/Admin/Settings/SsoSettings', $page['component']);
            self::assertTrue($this->shared()['features']['sso']);
        } finally {
            unset($_SERVER['ESCALATED_SSO'], $_ENV['ESCALATED_SSO']);
            putenv('ESCALATED_SSO');
        }
    }

    public function testSignInAnswers404WhileTheProviderIsNone(): void
    {
        $this->boot();

        $this->client->request('GET', '/support/sso/login');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/support/sso/jwt/callback?jwt='.$this->jwt());
        self::assertResponseStatusCodeSame(404);
    }

    public function testRoutesOfAnotherProviderAnswer404(): void
    {
        $this->boot();
        $this->jwtOn();

        $this->client->request('GET', '/support/sso/saml/metadata');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/support/sso/saml/acs');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/support/sso/oauth/callback');
        self::assertResponseStatusCodeSame(404);
    }

    // ---- Settings page -----------------------------------------------------

    public function testTheSettingsPageHasEndpointsAndMaskedSecrets(): void
    {
        $this->boot();
        $this->jwtOn();
        $this->settings()->set('sso_oauth_client_secret', 'oauth-client-secret');
        $this->client->loginUser($this->admin);

        $page = $this->visit(self::SETTINGS);
        self::assertSame('Escalated/Admin/Settings/SsoSettings', $page['component']);
        self::assertTrue($this->shared()['features']['sso']);
        self::assertSame('jwt', $page['props']['settings']['sso_provider']);
        self::assertSame('********', $page['props']['settings']['sso_jwt_secret']);
        self::assertSame('********', $page['props']['settings']['sso_oauth_client_secret']);
        self::assertStringNotContainsString(self::JWT_SECRET, (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('oauth-client-secret', (string) $this->client->getResponse()->getContent());
        self::assertSame([
            'login' => 'http://localhost/support/sso/login',
            'sp_entity_id' => self::METADATA,
            'metadata' => self::METADATA,
            'acs' => self::ACS,
            'oauth_redirect' => 'http://localhost/support/sso/oauth/callback',
            'jwt_callback' => 'http://localhost/support/sso/jwt/callback',
        ], $page['props']['endpoints']);
    }

    public function testSavingKeepsAMaskedSecretAndValidatesTheProvider(): void
    {
        $this->boot();
        $this->jwtOn();
        $this->client->loginUser($this->admin);

        $this->inertia('POST', self::SETTINGS, [
            'sso_provider' => 'jwt',
            'sso_url' => 'https://idp.example.test/jwt2',
            'sso_jwt_secret' => '********',
            'sso_jwt_algorithm' => 'HS512',
        ]);
        self::assertResponseRedirects();
        self::assertSame(self::JWT_SECRET, $this->settings()->get('sso_jwt_secret'), 'the mask keeps the stored secret');
        self::assertSame('HS512', $this->settings()->get('sso_jwt_algorithm'));
        self::assertSame('https://idp.example.test/jwt2', $this->settings()->get('sso_url'));

        $this->inertia('POST', self::SETTINGS, ['sso_provider' => 'oauth', 'sso_oauth_authorize_url' => 'not a url']);
        self::assertResponseRedirects();
        $errors = $this->lastErrors();
        self::assertArrayHasKey('sso_oauth_authorize_url', $errors);
        self::assertArrayHasKey('sso_oauth_token_url', $errors);
        self::assertArrayHasKey('sso_oauth_client_id', $errors);
        self::assertSame('jwt', $this->settings()->get('sso_provider'), 'nothing is saved when invalid');

        $this->inertia('POST', self::SETTINGS, ['sso_provider' => 'kerberos']);
        $errors = $this->lastErrors();
        self::assertArrayHasKey('sso_provider', $errors);
    }

    public function testTheSettingsPageIsForAdminsOnly(): void
    {
        $this->boot();
        $this->client->loginUser($this->person);

        $this->client->request('GET', self::SETTINGS);
        self::assertResponseStatusCodeSame(403);
    }

    // ---- SAML ---------------------------------------------------------------

    public function testASignedSamlAssertionForItsOwnRequestSignsIn(): void
    {
        $this->boot();
        $idp = $this->samlOn();
        $id = $this->samlStart();

        $this->samlPost($idp->response(self::ACS, self::METADATA, [
            'in_response_to' => $id,
            'attributes' => ['email' => 'Person@Example.com'],
        ]));
        self::assertResponseRedirects(self::DASHBOARD);
        $this->assertSignedInAs($this->person);

        $this->client->request('GET', self::DASHBOARD);
        self::assertResponseIsSuccessful('the sign-in lasts for the session');
    }

    public function testSpMetadataIsPublished(): void
    {
        $this->boot();
        $this->samlOn();

        $this->client->request('GET', '/support/sso/saml/metadata');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/samlmetadata+xml');
        self::assertStringContainsString(self::ACS, (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('entityID="'.self::METADATA.'"', (string) $this->client->getResponse()->getContent());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function badSamlResponses(): iterable
    {
        yield 'unsigned assertion' => [['sign_assertion' => false]];
        yield 'only the Response signed' => [['sign_assertion' => false, 'sign_response' => true]];
        yield 'signed by another key' => [['signing_key' => 'other']];
        yield 'tampered after signing' => [['tamper' => true]];
        yield 'wrong issuer' => [['issuer' => 'https://evil.example.test']];
        yield 'wrong audience' => [['audience' => 'https://other-sp.example.test']];
        yield 'wrong destination' => [['destination' => 'https://other-sp.example.test/acs']];
        yield 'wrong recipient' => [['recipient' => 'https://other-sp.example.test/acs']];
        yield 'expired' => [['not_before' => -3600, 'not_on_or_after' => -1800]];
        yield 'unknown InResponseTo' => [['in_response_to' => '_never-issued']];
        yield 'unsolicited' => [['in_response_to' => 'unsolicited']];
        yield 'encrypted assertion' => [['encrypted' => true]];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('badSamlResponses')]
    public function testSamlResponsesThatFailACheckAreRefused(array $options): void
    {
        $this->boot();
        $idp = $this->samlOn();
        $id = $this->samlStart();

        $tamper = (bool) ($options['tamper'] ?? false);
        $encrypted = (bool) ($options['encrypted'] ?? false);
        unset($options['tamper'], $options['encrypted']);
        if ('other' === ($options['signing_key'] ?? null)) {
            $options['signing_key'] = SamlIdp::keyPair()[0];
        }
        foreach (['not_before', 'not_on_or_after'] as $window) {
            if (isset($options[$window])) {
                $options[$window] = time() + (int) $options[$window];
            }
        }
        if (!\array_key_exists('in_response_to', $options)) {
            $options['in_response_to'] = $id;
        } elseif ('unsolicited' === $options['in_response_to']) {
            $options['in_response_to'] = null;
        }

        $xml = $idp->response(self::ACS, self::METADATA, $options);
        if ($tamper) {
            $xml = str_replace('person@example.com', 'admin@example.com', $xml);
        }
        if ($encrypted) {
            $xml = str_replace('<samlp:Status>', '<saml:EncryptedAssertion/><samlp:Status>', $xml);
        }

        $this->samlPost($xml);
        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('Single sign-on failed', (string) $this->client->getResponse()->getContent());
        $this->assertSignedOut();
    }

    public function testEverySamlResponseIsRefusedWithoutACertificate(): void
    {
        $this->boot();
        $idp = $this->samlOn();
        $this->settings()->set('sso_certificate', '');

        $this->client->request('GET', '/support/sso/login');
        self::assertResponseStatusCodeSame(403);
        $this->samlPost($idp->response(self::ACS, self::METADATA));
        self::assertResponseStatusCodeSame(403);
        $this->assertSignedOut();
    }

    public function testTheSameAssertionIsRefusedTheSecondTime(): void
    {
        $this->boot(['access' => ['sso' => true], 'sso' => ['allow_idp_initiated' => true]]);
        $idp = $this->samlOn();
        $xml = $idp->response(self::ACS, self::METADATA);

        $this->samlPost($xml);
        self::assertResponseRedirects(self::DASHBOARD);
        $this->client->getCookieJar()->clear();

        $this->samlPost($xml);
        self::assertResponseStatusCodeSame(403);
        $this->assertSignedOut();
    }

    public function testAnIdpInitiatedResponseIsAcceptedOnlyWhenAllowed(): void
    {
        $this->boot(['access' => ['sso' => true], 'sso' => ['allow_idp_initiated' => true]]);
        $idp = $this->samlOn();

        $this->samlPost($idp->response(self::ACS, self::METADATA));
        self::assertResponseRedirects(self::DASHBOARD);
        $this->assertSignedInAs($this->person);
    }

    public function testARequestIdIsAnsweredOnce(): void
    {
        $this->boot();
        $idp = $this->samlOn();
        $id = $this->samlStart();

        $this->samlPost($idp->response(self::ACS, self::METADATA, ['in_response_to' => $id]));
        self::assertResponseRedirects(self::DASHBOARD);
        $this->client->getCookieJar()->clear();

        $this->samlPost($idp->response(self::ACS, self::METADATA, ['in_response_to' => $id]));
        self::assertResponseStatusCodeSame(403);
    }

    // ---- OAuth ---------------------------------------------------------------

    public function testOauthSignsInWithStateAndPkce(): void
    {
        $this->boot();
        $this->oauthOn($this->oauthReplies());

        $this->client->request('GET', '/support/sso/login');
        $location = (string) $this->client->getResponse()->headers->get('Location');
        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertStringStartsWith('https://idp.example.test/authorize?', $location);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame('http://localhost/support/sso/oauth/callback', $query['redirect_uri']);
        self::assertNotSame('', $query['state']);
        $sessionBefore = $this->client->getRequest()->getSession()->getId();

        $this->client->request('GET', '/support/sso/oauth/callback?'.http_build_query(['code' => 'auth-code', 'state' => $query['state']]));
        self::assertResponseRedirects(self::DASHBOARD);
        $this->assertSignedInAs($this->person);
        self::assertNotSame($sessionBefore, $this->client->getRequest()->getSession()->getId(), 'signing in regenerates the session ID');

        self::assertCount(2, $this->history);
        parse_str((string) $this->history[0]['request']->getBody(), $tokenRequest);
        self::assertSame('auth-code', $tokenRequest['code']);
        self::assertNotSame('', $tokenRequest['code_verifier']);
        self::assertSame($query['code_challenge'], rtrim(strtr(base64_encode(hash('sha256', $tokenRequest['code_verifier'], true)), '+/', '-_'), '='));
        self::assertSame('Bearer at-123', $this->history[1]['request']->getHeaderLine('Authorization'));
    }

    public function testAnOauthCallbackWithAMissingOrWrongStateIsRefused(): void
    {
        $this->boot();
        $this->oauthOn($this->oauthReplies());

        $this->client->request('GET', '/support/sso/login');
        $this->client->request('GET', '/support/sso/oauth/callback?code=auth-code&state=forged');
        self::assertResponseStatusCodeSame(403);
        $this->assertSignedOut();

        $this->client->request('GET', '/support/sso/login');
        $this->client->request('GET', '/support/sso/oauth/callback?code=auth-code');
        self::assertResponseStatusCodeSame(403);
        $this->assertSignedOut();

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/support/sso/oauth/callback?code=auth-code&state=anything');
        self::assertResponseStatusCodeSame(403, 'no state in the session');

        self::assertSame([], $this->history, 'nothing is sent to the provider');
    }

    public function testTheStateIsSingleUse(): void
    {
        $this->boot();
        $this->oauthOn([...$this->oauthReplies(), ...$this->oauthReplies()]);

        $this->client->request('GET', '/support/sso/login');
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $query);

        $this->client->request('GET', '/support/sso/oauth/callback?'.http_build_query(['code' => 'auth-code', 'state' => $query['state']]));
        self::assertResponseRedirects(self::DASHBOARD);

        $this->client->request('GET', '/support/sso/oauth/callback?'.http_build_query(['code' => 'auth-code', 'state' => $query['state']]));
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnOauthErrorOrUnverifiedEmailIsRefused(): void
    {
        $this->boot();
        $this->oauthOn([
            new PsrResponse(200, ['Content-Type' => 'application/json'], (string) json_encode(['access_token' => 'at-123', 'token_type' => 'Bearer'])),
            new PsrResponse(200, ['Content-Type' => 'application/json'], (string) json_encode(['email' => 'person@example.com', 'email_verified' => false])),
        ]);

        $this->client->request('GET', '/support/sso/login');
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $query);
        $this->client->request('GET', '/support/sso/oauth/callback?'.http_build_query(['error' => 'access_denied', 'state' => $query['state']]));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/support/sso/login');
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $query);
        $this->client->request('GET', '/support/sso/oauth/callback?'.http_build_query(['code' => 'auth-code', 'state' => $query['state']]));
        self::assertResponseStatusCodeSame(403);
        $this->assertSignedOut();
    }

    // ---- JWT -----------------------------------------------------------------

    public function testAValidJwtSignsIn(): void
    {
        $this->boot();
        $this->jwtOn();

        $this->client->request('GET', '/support/sso/login');
        self::assertResponseRedirects('https://idp.example.test/jwt?return_to='.urlencode('http://localhost/support/sso/jwt/callback'));

        $this->client->request('GET', '/support/sso/jwt/callback?jwt='.$this->jwt());
        self::assertResponseRedirects(self::DASHBOARD);
        $this->assertSignedInAs($this->person);
    }

    public function testAJwtCanBePosted(): void
    {
        $this->boot();
        $this->jwtOn();

        $this->client->request('POST', '/support/sso/jwt/callback', ['jwt' => $this->jwt()]);
        self::assertResponseRedirects(self::DASHBOARD);
        $this->assertSignedInAs($this->person);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badJwts(): iterable
    {
        yield 'bad signature' => ['bad signature'];
        yield 'alg none' => ['alg none'];
        yield 'other algorithm' => ['other algorithm'];
        yield 'no exp' => ['no exp'];
        yield 'expired' => ['expired'];
        yield 'wrong audience' => ['wrong audience'];
        yield 'no jti' => ['no jti'];
        yield 'no email' => ['no email'];
    }

    #[DataProvider('badJwts')]
    public function testJwtsThatFailACheckAreRefused(string $case): void
    {
        $this->boot();
        $this->jwtOn();
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

        $token = match ($case) {
            'bad signature' => $this->jwt([], 'another-secret-that-is-also-long-enough-0123456789'),
            'alg none' => $b64('{"alg":"none","typ":"JWT"}').'.'.$b64((string) json_encode(['email' => 'person@example.com', 'aud' => 'http://localhost', 'exp' => time() + 60, 'jti' => 'x'])).'.',
            'other algorithm' => $this->jwt([], self::JWT_SECRET.self::JWT_SECRET, 'HS512'),
            'no exp' => JWT::encode(['email' => 'person@example.com', 'aud' => 'http://localhost', 'jti' => 'x1'], self::JWT_SECRET, 'HS256'),
            'expired' => $this->jwt(['exp' => time() - 3600, 'iat' => time() - 7200]),
            'wrong audience' => $this->jwt(['aud' => 'https://elsewhere.example.test']),
            'no jti' => JWT::encode(['email' => 'person@example.com', 'aud' => 'http://localhost', 'exp' => time() + 60], self::JWT_SECRET, 'HS256'),
            'no email' => $this->jwt(['email' => 'not-an-email']),
        };
        self::assertSame(2, substr_count($token, '.'));

        $this->client->request('GET', '/support/sso/jwt/callback?jwt='.$token);
        self::assertResponseStatusCodeSame(403);
        $this->assertSignedOut();
    }

    public function testAJwtIsRefusedTheSecondTime(): void
    {
        $this->boot();
        $this->jwtOn();
        $token = $this->jwt();

        $this->client->request('GET', '/support/sso/jwt/callback?jwt='.$token);
        self::assertResponseRedirects(self::DASHBOARD);
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', '/support/sso/jwt/callback?jwt='.$token);
        self::assertResponseStatusCodeSame(403);
        $this->assertSignedOut();
    }

    public function testAnRs256JwtIsCheckedAgainstThePublicKey(): void
    {
        $this->boot();
        [$private, $certificate] = SamlIdp::keyPair();
        $publicKey = openssl_pkey_get_public($certificate);
        self::assertNotFalse($publicKey);
        $details = openssl_pkey_get_details($publicKey);
        self::assertIsArray($details);
        $public = (string) $details['key'];
        $this->jwtOn(['sso_jwt_algorithm' => 'RS256', 'sso_jwt_secret' => $public]);

        $this->client->request('GET', '/support/sso/jwt/callback?jwt='.$this->jwt([], self::JWT_SECRET, 'HS256'));
        self::assertResponseStatusCodeSame(403, 'an HS256 token is refused when RS256 is configured');

        $this->client->request('GET', '/support/sso/jwt/callback?jwt='.$this->jwt([], $private, 'RS256'));
        self::assertResponseRedirects(self::DASHBOARD);
    }

    public function testTheJwtAudienceCanBeConfigured(): void
    {
        $this->boot(['access' => ['sso' => true], 'sso' => ['jwt_audience' => 'https://support.example.test']]);
        $this->jwtOn();

        $this->client->request('GET', '/support/sso/jwt/callback?jwt='.$this->jwt());
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/support/sso/jwt/callback?jwt='.$this->jwt(['aud' => 'https://support.example.test']));
        self::assertResponseRedirects(self::DASHBOARD);
    }

    // ---- Provisioning, role mapping, landing --------------------------------

    public function testAnUnknownEmailIsRefusedWhileUserCreationIsOff(): void
    {
        $this->boot();
        $this->jwtOn();

        $this->client->request('GET', '/support/sso/jwt/callback?jwt='.$this->jwt(['email' => 'new@example.com']));
        self::assertResponseStatusCodeSame(403);
        $this->assertSignedOut();
        self::assertNull($this->user('new@example.com'));
    }

    public function testAnUnknownEmailCreatesAUserWhileUserCreationIsOn(): void
    {
        $this->boot(['access' => ['sso' => true], 'sso' => ['create_users' => true]]);
        $this->jwtOn();

        $this->client->request('GET', '/support/sso/jwt/callback?jwt='.$this->jwt(['email' => 'new@example.com', 'name' => 'New Person']));
        self::assertResponseRedirects('/support/customer/tickets', null, 'a created user is no agent: SSO grants no gate');

        $created = $this->user('new@example.com');
        self::assertNotNull($created);
        $this->assertSignedInAs($created);
        self::assertSame(['ROLE_USER'], $created->getRoles());
    }

    public function testTheRoleClaimIsMappedOnlyWithRolesAndRoleSyncOn(): void
    {
        // Roles on, sync off: nothing changes.
        $this->boot(['access' => ['sso' => true, 'roles_permissions' => true]]);
        $this->jwtOn();
        $this->client->request('GET', '/support/sso/jwt/callback?jwt='.$this->jwt(['role' => 'light_agent']));
        self::assertResponseRedirects();
        self::assertSame([], $this->permissionsOf($this->person));

        // Sync on, roles off: nothing changes.
        $this->boot(['access' => ['sso' => true], 'sso' => ['sync_roles' => true]]);
        $this->jwtOn();
        $this->client->request('GET', '/support/sso/jwt/callback?jwt='.$this->jwt(['role' => 'light_agent']));
        self::assertResponseRedirects();
        self::assertSame([], $this->permissionsOf($this->person));

        // Both on: the role replaces the user's roles; an unknown one changes nothing.
        $this->boot(['access' => ['sso' => true, 'roles_permissions' => true], 'sso' => ['sync_roles' => true]]);
        $this->jwtOn();
        $admin = static::entityManager()->getRepository(Role::class)->findOneBy(['slug' => 'admin']);
        \assert($admin instanceof Role);
        $this->permissions()->assign((int) $this->person->getId(), $admin);

        $this->client->request('GET', '/support/sso/jwt/callback?jwt='.$this->jwt(['role' => 'light_agent']));
        self::assertResponseRedirects();
        $permissions = $this->permissionsOf($this->person);
        self::assertContains('reply.create_internal', $permissions);
        self::assertNotContains('ticket.assign', $permissions);

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/support/sso/jwt/callback?jwt='.$this->jwt(['role' => 'no-such-role']));
        self::assertResponseRedirects();
        self::assertSame($permissions, $this->permissionsOf($this->person));
    }

    public function testAnAdminWhoIsNoAgentLandsOnReportsAndRedirectToWins(): void
    {
        $this->boot();
        $this->jwtOn();
        $em = static::entityManager();
        $em->remove($em->getRepository(AgentProfile::class)->findOneBy(['userId' => $this->admin->getId()]) ?? throw new \RuntimeException());
        $em->flush();

        $this->client->request('GET', '/support/sso/jwt/callback?jwt='.$this->jwt(['email' => 'admin@example.com']));
        self::assertResponseRedirects('/support/admin/reports');

        $this->boot(['access' => ['sso' => true], 'sso' => ['redirect_to' => '/home']]);
        $this->jwtOn();
        $this->client->request('GET', '/support/sso/jwt/callback?jwt='.$this->jwt());
        self::assertResponseRedirects('/home');
    }

    // ---- Two-factor still applies --------------------------------------------

    public function testAnEnrolledUserSigningInThroughSsoIsStillChallenged(): void
    {
        $this->boot(['access' => ['sso' => true, 'two_factor' => true]]);
        $this->jwtOn();
        $twoFactor = static::getContainer()->get(TwoFactorService::class);
        \assert($twoFactor instanceof TwoFactorService);
        $twoFactor->begin((int) $this->person->getId());
        $pending = $twoFactor->pendingFor((int) $this->person->getId());
        self::assertNotNull($pending);
        $twoFactor->confirm($pending);

        $this->client->request('GET', '/support/sso/jwt/callback?jwt='.$this->jwt());
        self::assertResponseRedirects(self::DASHBOARD);

        $this->client->request('GET', self::DASHBOARD);
        self::assertResponseRedirects('/support/two-factor/challenge');
    }

    // ---- Helpers ---------------------------------------------------------------

    /**
     * @param array<string, mixed> $config
     */
    private function boot(array $config = ['access' => ['sso' => true]]): void
    {
        static::ensureKernelShutdown();
        $this->client = static::clientWithSchema(['escalated' => ['user_class' => TestUser::class, ...$config]]);
        $this->history = [];

        $em = static::entityManager();
        $this->admin = new TestUser('admin@example.com', ['ROLE_ESCALATED_ADMIN']);
        $this->person = new TestUser('person@example.com');
        $em->persist($this->admin);
        $em->persist($this->person);
        $em->flush();
        foreach ([$this->admin, $this->person] as $user) {
            $em->persist((new AgentProfile())->setUserId((int) $user->getId()));
        }
        $em->flush();

        $seeder = static::getContainer()->get(PermissionSeeder::class);
        \assert($seeder instanceof PermissionSeeder);
        $seeder->seed();
    }

    private function samlOn(): SamlIdp
    {
        $idp = new SamlIdp();
        $this->configure([
            'sso_provider' => 'saml',
            'sso_entity_id' => $idp->entityId,
            'sso_login_url' => 'https://idp.example.test/sso',
            'sso_certificate' => $idp->certificate,
        ]);

        return $idp;
    }

    /** Start a SAML sign-in and return the AuthnRequest ID it issued. */
    private function samlStart(): string
    {
        $this->client->request('GET', '/support/sso/login');
        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith('https://idp.example.test/sso?SAMLRequest=', $location);

        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        $xml = (string) gzinflate((string) base64_decode((string) $query['SAMLRequest'], true));
        self::assertSame(1, preg_match('/ID="([^"]+)"/', $xml, $m));

        return $m[1];
    }

    private function samlPost(string $xml): void
    {
        $this->client->request('POST', '/support/sso/saml/acs', ['SAMLResponse' => base64_encode($xml)]);
    }

    /**
     * @param array<string, string> $settings
     */
    private function jwtOn(array $settings = []): void
    {
        $this->configure([
            'sso_provider' => 'jwt',
            'sso_login_url' => 'https://idp.example.test/jwt',
            'sso_jwt_secret' => self::JWT_SECRET,
            'sso_jwt_algorithm' => 'HS256',
            ...$settings,
        ]);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function jwt(array $claims = [], string $key = self::JWT_SECRET, string $alg = 'HS256'): string
    {
        return JWT::encode([
            'email' => 'person@example.com',
            'name' => 'Person',
            'aud' => 'http://localhost',
            'exp' => time() + 120,
            'iat' => time(),
            'jti' => bin2hex(random_bytes(10)),
            ...$claims,
        ], $key, $alg);
    }

    /**
     * @param list<PsrResponse> $responses
     */
    private function oauthOn(array $responses): void
    {
        $this->configure([
            'sso_provider' => 'oauth',
            'sso_oauth_authorize_url' => 'https://idp.example.test/authorize',
            'sso_oauth_token_url' => 'https://idp.example.test/token',
            'sso_oauth_userinfo_url' => 'https://idp.example.test/userinfo',
            'sso_oauth_client_id' => 'client-id',
            'sso_oauth_client_secret' => 'client-secret',
        ]);

        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        static::getContainer()->set('escalated.sso.http_client', new Client(['handler' => $stack]));
    }

    /**
     * @return list<PsrResponse>
     */
    private function oauthReplies(): array
    {
        return [
            new PsrResponse(200, ['Content-Type' => 'application/json'], (string) json_encode(['access_token' => 'at-123', 'token_type' => 'Bearer', 'expires_in' => 3600])),
            new PsrResponse(200, ['Content-Type' => 'application/json'], (string) json_encode(['email' => 'person@example.com', 'name' => 'Person', 'email_verified' => true])),
        ];
    }

    /**
     * @param array<string, string> $settings
     */
    private function configure(array $settings): void
    {
        foreach ($settings as $key => $value) {
            $this->settings()->set($key, $value);
        }
    }

    /**
     * @return array<string, string>
     */
    private function lastErrors(): array
    {
        $errors = $this->client->getRequest()->getSession()->getFlashBag()->peek('errors');
        $last = end($errors);

        return \is_array($last) ? $last : [];
    }

    private function assertSignedInAs(TestUser $user): void
    {
        $tokens = static::getContainer()->get('security.token_storage');
        \assert($tokens instanceof TokenStorageInterface);
        self::assertSame($user->getEmail(), $tokens->getToken()?->getUserIdentifier(), 'signed in as '.$user->getEmail());
    }

    private function assertSignedOut(): void
    {
        $tokens = static::getContainer()->get('security.token_storage');
        \assert($tokens instanceof TokenStorageInterface);
        self::assertNull($tokens->getToken(), 'nobody is signed in');
    }

    private function user(string $email): ?TestUser
    {
        static::entityManager()->clear();

        return static::entityManager()->getRepository(TestUser::class)->findOneBy(['email' => $email]);
    }

    /**
     * @return list<string>
     */
    private function permissionsOf(TestUser $user): array
    {
        return $this->permissions()->ofUserKey((int) $user->getId());
    }

    private function permissions(): UserPermissions
    {
        $permissions = static::getContainer()->get(UserPermissions::class);
        \assert($permissions instanceof UserPermissions);

        return $permissions;
    }

    private function settings(): SettingsService
    {
        $settings = static::getContainer()->get(SettingsService::class);
        \assert($settings instanceof SettingsService);

        return $settings;
    }

    /**
     * @return array<string, mixed>
     */
    private function visit(string $uri): array
    {
        $this->inertia('GET', $uri);
        self::assertResponseIsSuccessful($uri);
        $page = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($page);

        return $page;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function inertia(string $method, string $uri, array $body = []): void
    {
        $this->client->request($method, $uri, server: [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_ACCEPT' => 'text/html, application/xhtml+xml',
            'CONTENT_TYPE' => 'application/json',
        ], content: 'GET' === $method ? null : (string) json_encode($body));
    }

    /**
     * @return array<string, mixed>
     */
    private function shared(): array
    {
        $shared = $this->client->getRequest()->attributes->get('_escalated_shared');
        self::assertIsArray($shared);

        return $shared;
    }
}
