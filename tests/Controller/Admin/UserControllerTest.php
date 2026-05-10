<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Escalated\Symfony\Controller\Admin\UserController;
use Escalated\Symfony\Rendering\UiRendererInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Unit tests for the admin Users management controller.
 *
 * Mirrors the seven behaviours covered in escalated-laravel#94
 * (tests/Feature/Admin/UserControllerTest.php). The Laravel suite boots
 * the full framework; the Symfony bundle has no kernel-test harness, so
 * we follow the pattern already established by
 * {@see \Escalated\Symfony\Tests\Controller\InboundEmailControllerTest}
 * and drive the controller in isolation, overriding the few helpers
 * AbstractController exposes (`denyAccessUnlessGranted`, `getUser`,
 * `addFlash`, `redirectToRoute`) plus the Doctrine load via a single
 * protected seam (`loadUsers()`).
 */
class UserControllerTest extends TestCase
{
    /** @var array<int, FakeUser> */
    private array $store = [];

    private FakeUiRenderer $renderer;

    protected function setUp(): void
    {
        $this->store = [];
        $this->renderer = new FakeUiRenderer();
    }

    public function testIndexListsUsersWithAdminAndAgentFlags(): void
    {
        $admin = $this->seed(1, 'Alice', 'admin@example.com', [UserController::ROLE_ADMIN, UserController::ROLE_AGENT]);
        $this->seed(2, 'Carl', 'customer@example.com', []);
        $this->seed(3, 'Anne', 'agent@example.com', [UserController::ROLE_AGENT]);

        $controller = $this->controller(currentUser: $admin);
        $controller->index(new Request());

        $rows = $this->renderer->lastProps['users']['data'];
        $emails = array_column($rows, 'email');
        // Sort contract: admins first, then agents, then by id asc.
        $this->assertSame(['admin@example.com', 'agent@example.com', 'customer@example.com'], $emails);
        $this->assertSame(1, $rows[0]['id']);
        $this->assertTrue($rows[0]['is_admin']);
        $this->assertTrue($rows[0]['is_agent']);
        $this->assertFalse($rows[1]['is_admin']);
        $this->assertTrue($rows[1]['is_agent']);
        $this->assertSame('', $this->renderer->lastProps['filters']['search']);
        $this->assertSame(1, $this->renderer->lastProps['currentUserId']);
    }

    public function testNonAdminsAreBlockedFromTheUserList(): void
    {
        $agent = $this->seed(1, 'Anne', 'agent@example.com', [UserController::ROLE_AGENT]);

        $controller = $this->controller(currentUser: $agent, isAdmin: false);

        $this->expectException(AccessDeniedException::class);
        $controller->index(new Request());
    }

    public function testPromotesAUserToAdminSetsBothFlags(): void
    {
        $admin = $this->seed(1, 'Alice', 'admin@example.com', [UserController::ROLE_ADMIN, UserController::ROLE_AGENT]);
        $target = $this->seed(2, 'Sam', 'someone@example.com', []);

        $controller = $this->controller(currentUser: $admin);
        $response = $controller->updateRole(2, $this->jsonRequest(['role' => 'admin', 'value' => true]));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $roles = $target->getStoredRoles();
        $this->assertContains(UserController::ROLE_ADMIN, $roles);
        $this->assertContains(UserController::ROLE_AGENT, $roles);
    }

    public function testPromotesAUserToAgentOnly(): void
    {
        $admin = $this->seed(1, 'Alice', 'admin@example.com', [UserController::ROLE_ADMIN, UserController::ROLE_AGENT]);
        $target = $this->seed(2, 'Sam', 'someone@example.com', []);

        $controller = $this->controller(currentUser: $admin);
        $controller->updateRole(2, $this->jsonRequest(['role' => 'agent', 'value' => true]));

        $roles = $target->getStoredRoles();
        $this->assertContains(UserController::ROLE_AGENT, $roles);
        $this->assertNotContains(UserController::ROLE_ADMIN, $roles);
    }

    public function testPreventsAdminsFromDemotingThemselves(): void
    {
        $admin = $this->seed(7, 'Alice', 'admin@example.com', [UserController::ROLE_ADMIN, UserController::ROLE_AGENT]);

        $controller = $this->controller(currentUser: $admin);
        $response = $controller->updateRole(7, $this->jsonRequest(['role' => 'admin', 'value' => false]));

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertContains(UserController::ROLE_ADMIN, $admin->getStoredRoles());
        // Should also surface an error flash for the user.
        $this->assertNotEmpty(array_filter($controller->flashes, fn ($f) => 'error' === $f['type']));
    }

    public function testDemotingAnAdminViaAgentTogglesOffBothFlags(): void
    {
        $admin = $this->seed(1, 'Alice', 'admin@example.com', [UserController::ROLE_ADMIN, UserController::ROLE_AGENT]);
        $target = $this->seed(2, 'Bob', 'someone@example.com', [UserController::ROLE_ADMIN, UserController::ROLE_AGENT]);

        $controller = $this->controller(currentUser: $admin);
        $controller->updateRole(2, $this->jsonRequest(['role' => 'agent', 'value' => false]));

        $roles = $target->getStoredRoles();
        $this->assertNotContains(UserController::ROLE_ADMIN, $roles);
        $this->assertNotContains(UserController::ROLE_AGENT, $roles);
    }

    public function testFiltersUsersBySearchTerm(): void
    {
        $admin = $this->seed(1, 'Alice', 'admin@example.com', [UserController::ROLE_ADMIN, UserController::ROLE_AGENT]);
        $this->seed(2, 'Jane', 'jane@acme.test', []);
        $this->seed(3, 'Bob', 'bob@globex.test', []);

        $controller = $this->controller(currentUser: $admin);
        $request = new Request(query: ['search' => 'acme']);
        $controller->index($request);

        $emails = array_column($this->renderer->lastProps['users']['data'], 'email');
        $this->assertContains('jane@acme.test', $emails);
        $this->assertNotContains('bob@globex.test', $emails);
        $this->assertSame('acme', $this->renderer->lastProps['filters']['search']);
    }

    /** @param list<string> $roles */
    private function seed(int $id, string $name, string $email, array $roles): FakeUser
    {
        $user = new FakeUser($id, $name, $email, $roles);
        $this->store[$id] = $user;

        return $user;
    }

    /** @param array<string, mixed> $payload */
    private function jsonRequest(array $payload): Request
    {
        return new Request(
            server: ['HTTP_CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    private function controller(FakeUser $currentUser, bool $isAdmin = true): TestableUserController
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = new InMemoryUserRepository($this->store);
        $em->method('getRepository')->willReturn($repo);
        $em->method('flush'); // no-op

        $controller = new TestableUserController(
            $em,
            $this->renderer,
            FakeUser::class,
            $this->store,
        );
        $controller->setCurrentUser($currentUser);
        $controller->setIsAdmin($isAdmin);

        return $controller;
    }
}

/**
 * Stand-in host User. Implements only what UserController reads: id,
 * name, email, getRoles(), setRoles(). Tracks the actual stored roles
 * separately from any framework-level role expansion so we can assert
 * what the controller persisted.
 */
class FakeUser implements UserInterface
{
    /** @param list<string> $roles */
    public function __construct(
        private int $id,
        private string $name,
        private string $email,
        private array $roles,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return $this->roles;
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): self
    {
        $this->roles = array_values($roles);

        return $this;
    }

    /** @return list<string> */
    public function getStoredRoles(): array
    {
        return $this->roles;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->id;
    }

    public function eraseCredentials(): void
    {
    }
}

/**
 * Minimal repository double that only implements find(); the listing
 * path is overridden via TestableUserController::loadUsers(). Extends
 * the real EntityRepository so the return-type constraint on
 * EntityManagerInterface::getRepository() is satisfied without having
 * to wire up a full ClassMetadata + EntityManager.
 */
class InMemoryUserRepository extends EntityRepository
{
    /** @param array<int, FakeUser> $store */
    public function __construct(private array &$store)
    {
        // Skip the parent constructor; we only expose find().
    }

    public function find($id, $lockMode = null, $lockVersion = null): ?object
    {
        $key = is_numeric($id) ? (int) $id : $id;

        return $this->store[$key] ?? null;
    }
}

class FakeUiRenderer implements UiRendererInterface
{
    public string $lastPage = '';

    /** @var array<string, mixed> */
    public array $lastProps = [];

    public function render(string $page, array $props = []): Response
    {
        $this->lastPage = $page;
        $this->lastProps = $props;

        return new JsonResponse(['component' => $page, 'props' => $props]);
    }
}

/**
 * Subclass that bypasses the framework wiring AbstractController
 * normally relies on (security checker, flash bag, router, current
 * token). Each override stays functionally honest with production:
 * denying when not admin, recording flash messages, returning real
 * RedirectResponses, and shortcutting the Doctrine listing via the
 * in-memory store applied in {@see loadUsers()}.
 */
class TestableUserController extends UserController
{
    private bool $isAdmin = true;
    private ?FakeUser $currentUser = null;

    /** @var list<array{type: string, message: string}> */
    public array $flashes = [];

    /** @param array<int, FakeUser> $store */
    public function __construct(
        EntityManagerInterface $em,
        UiRendererInterface $renderer,
        string $userClass,
        private array &$store,
    ) {
        parent::__construct($em, $renderer, $userClass);
    }

    public function setIsAdmin(bool $value): void
    {
        $this->isAdmin = $value;
    }

    public function setCurrentUser(?FakeUser $user): void
    {
        $this->currentUser = $user;
    }

    protected function denyAccessUnlessGranted(mixed $attribute, mixed $subject = null, string $message = 'Access Denied.'): void
    {
        if ('ESCALATED_ADMIN' === $attribute && !$this->isAdmin) {
            throw new AccessDeniedException($message);
        }
    }

    protected function getUser(): ?UserInterface
    {
        return $this->currentUser;
    }

    protected function addFlash(string $type, mixed $message): void
    {
        $this->flashes[] = ['type' => $type, 'message' => (string) $message];
    }

    protected function redirectToRoute(string $route, array $parameters = [], int $status = 302): RedirectResponse
    {
        return new RedirectResponse('/'.$route, $status);
    }

    protected function loadUsers(string $search, int $page, int $perPage): array
    {
        $rows = array_values($this->store);
        if ('' !== $search) {
            $needle = strtolower($search);
            $rows = array_values(array_filter(
                $rows,
                fn (FakeUser $u) => str_contains(strtolower($u->getEmail()), $needle)
                    || str_contains(strtolower($u->getName()), $needle),
            ));
        }
        usort($rows, fn (FakeUser $a, FakeUser $b) => $a->getId() <=> $b->getId());

        $total = count($rows);
        $rows = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return [array_values($rows), $total];
    }
}
