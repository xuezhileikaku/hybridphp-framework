<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use HybridPHP\Core\Auth\AuthManager;
use HybridPHP\Core\Auth\User;
use HybridPHP\Core\Auth\UserInterface;
use HybridPHP\Core\Container;
use function Amp\async;

/**
 * Auth unit tests
 */
class AuthTest extends TestCase
{
    private Container $container;
    private array $authConfig;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->authConfig = [
            'default' => 'jwt',
            'guards' => [
                'jwt' => [
                    'driver' => 'jwt',
                    'provider' => 'users',
                    'secret' => 'test-secret-key-for-jwt-testing-12345',
                    'algorithm' => 'HS256',
                    'ttl' => 3600,
                    'refresh_ttl' => 86400,
                ],
            ],
            'providers' => [
                'users' => [
                    'driver' => 'database',
                    'table' => 'users',
                ],
            ],
        ];
    }

    public function testAuthManagerCreation(): void
    {
        $authManager = new AuthManager($this->container, $this->authConfig);
        
        $this->assertInstanceOf(AuthManager::class, $authManager);
    }

    public function testGuardThrowsExceptionForUndefinedGuard(): void
    {
        $authManager = new AuthManager($this->container, $this->authConfig);
        
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Auth guard [nonexistent] is not defined');
        
        $authManager->guard('nonexistent');
    }

    public function testUserComponentCreation(): void
    {
        $authManager = new AuthManager($this->container, $this->authConfig);
        $user = new User($authManager);
        
        $this->assertInstanceOf(User::class, $user);
    }

    public function testUserGetAuthManager(): void
    {
        $authManager = new AuthManager($this->container, $this->authConfig);
        $user = new User($authManager);
        
        $this->assertSame($authManager, $user->getAuthManager());
    }

    public function testMockUserInterface(): void
    {
        $mockUser = new MockUser(1, 'testuser', 'test@example.com');
        
        $this->assertEquals(1, $mockUser->getId());
        $this->assertEquals('testuser', $mockUser->getUsername());
        $this->assertEquals('test@example.com', $mockUser->getEmail());
        $this->assertTrue($mockUser->isActive());
    }

    public function testMockUserRoles(): void
    {
        $mockUser = new MockUser(1, 'admin', 'admin@example.com', ['admin', 'user']);
        
        $this->assertTrue($mockUser->hasRole('admin'));
        $this->assertTrue($mockUser->hasRole('user'));
        $this->assertFalse($mockUser->hasRole('superadmin'));
    }

    public function testMockUserPermissions(): void
    {
        $mockUser = new MockUser(1, 'admin', 'admin@example.com', [], ['read', 'write', 'delete']);
        
        $this->assertTrue($mockUser->hasPermission('read'));
        $this->assertTrue($mockUser->hasPermission('write'));
        $this->assertFalse($mockUser->hasPermission('admin'));
    }

    public function testMockUserPasswordVerification(): void
    {
        $mockUser = new MockUser(1, 'testuser', 'test@example.com');
        
        $this->assertTrue($mockUser->verifyPassword('password'));
        $this->assertFalse($mockUser->verifyPassword('wrongpassword'));
    }

    public function testMockUserToArray(): void
    {
        $mockUser = new MockUser(1, 'testuser', 'test@example.com', ['user'], ['read']);
        
        $array = $mockUser->toArray();
        
        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('username', $array);
        $this->assertArrayHasKey('email', $array);
        $this->assertArrayHasKey('roles', $array);
        $this->assertArrayHasKey('permissions', $array);
    }
}


/**
 * Mock user implementation for testing
 */
class MockUser implements UserInterface
{
    private int $id;
    private string $username;
    private string $email;
    private string $password;
    private array $roles;
    private array $permissions;
    private bool $active;

    public function __construct(
        int $id,
        string $username,
        string $email,
        array $roles = [],
        array $permissions = [],
        bool $active = true
    ) {
        $this->id = $id;
        $this->username = $username;
        $this->email = $email;
        $this->password = password_hash('password', PASSWORD_DEFAULT);
        $this->roles = $roles;
        $this->permissions = $permissions;
        $this->active = $active;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->password);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getPermissions(): array
    {
        return $this->permissions;
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'roles' => $this->roles,
            'permissions' => $this->permissions,
            'active' => $this->active,
        ];
    }
}
