<?php

declare(strict_types=1);

namespace HybridPHP\Core\Auth\Providers;

use Amp\Future;
use HybridPHP\Core\Auth\UserInterface;
use HybridPHP\Core\Auth\UserProviderInterface;
use HybridPHP\Core\Container;
use HybridPHP\Core\Database\DatabaseInterface;
use function Amp\async;

/**
 * Database user provider
 */
class DatabaseUserProvider implements UserProviderInterface
{
    private Container $container;
    private array $config;
    private ?DatabaseInterface $db = null;

    public function __construct(Container $container, array $config)
    {
        $this->container = $container;
        $this->config = $config;
    }

    /**
     * Get database instance
     *
     * @return DatabaseInterface
     */
    private function getDatabase(): DatabaseInterface
    {
        if (!$this->db) {
            $this->db = $this->container->get('db');
        }
        return $this->db;
    }

    /**
     * Retrieve a user by their unique identifier
     *
     * @param mixed $identifier
     * @return Future<UserInterface|null>
     */
    public function retrieveById($identifier): Future
    {
        return async(function () use ($identifier) {
            $db = $this->getDatabase();
            $table = $this->config['table'] ?? 'users';
            
            $result = $db->query(
                "SELECT * FROM {$table} WHERE id = ? LIMIT 1",
                [$identifier]
            )->await();

            if (empty($result)) {
                return null;
            }

            return $this->createUserFromData($result[0]);
        });
    }

    /**
     * Retrieve a user by their unique identifier and "remember me" token
     *
     * @param mixed $identifier
     * @param string $token
     * @return Future<UserInterface|null>
     */
    public function retrieveByToken($identifier, string $token): Future
    {
        return async(function () use ($identifier, $token) {
            $db = $this->getDatabase();
            $table = $this->config['table'] ?? 'users';
            
            $result = $db->query(
                "SELECT * FROM {$table} WHERE id = ? AND remember_token = ? LIMIT 1",
                [$identifier, $token]
            )->await();

            if (empty($result)) {
                return null;
            }

            return $this->createUserFromData($result[0]);
        });
    }

    /**
     * Update the "remember me" token for the given user
     *
     * @param UserInterface $user
     * @param string $token
     * @return Future<void>
     */
    public function updateRememberToken(UserInterface $user, string $token): Future
    {
        return async(function () use ($user, $token) {
            $db = $this->getDatabase();
            $table = $this->config['table'] ?? 'users';
            
            $db->execute(
                "UPDATE {$table} SET remember_token = ? WHERE id = ?",
                [$token, $user->getId()]
            )->await();
        });
    }

    /**
     * Retrieve a user by the given credentials
     *
     * @param array $credentials
     * @return Future<UserInterface|null>
     */
    public function retrieveByCredentials(array $credentials): Future
    {
        return async(function () use ($credentials) {
            $db = $this->getDatabase();
            $table = $this->config['table'] ?? 'users';
            
            $conditions = [];
            $params = [];
            
            foreach ($credentials as $key => $value) {
                if ($key !== 'password') {
                    $conditions[] = "{$key} = ?";
                    $params[] = $value;
                }
            }

            if (empty($conditions)) {
                return null;
            }

            $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $conditions) . " LIMIT 1";
            $result = $db->query($sql, $params)->await();

            if (empty($result)) {
                return null;
            }

            return $this->createUserFromData($result[0]);
        });
    }

    /**
     * Validate a user against the given credentials
     *
     * @param UserInterface $user
     * @param array $credentials
     * @return Future<bool>
     */
    public function validateCredentials(UserInterface $user, array $credentials): Future
    {
        return async(function () use ($user, $credentials) {
            if (!isset($credentials['password'])) {
                return false;
            }

            return $user->verifyPassword($credentials['password']);
        });
    }

    /**
     * Rehash the user's password if required and supported
     *
     * @param UserInterface $user
     * @param array $credentials
     * @param bool $force
     * @return Future<void>
     */
    public function rehashPasswordIfRequired(UserInterface $user, array $credentials, bool $force = false): Future
    {
        return async(function () use ($user, $credentials, $force) {
            if (!isset($credentials['password'])) {
                return;
            }

            $password = $credentials['password'];
            $hashedPassword = $user->getPassword();

            if ($force || password_needs_rehash($hashedPassword, PASSWORD_DEFAULT)) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                
                $db = $this->getDatabase();
                $table = $this->config['table'] ?? 'users';
                
                $db->execute(
                    "UPDATE {$table} SET password = ? WHERE id = ?",
                    [$newHash, $user->getId()]
                )->await();
            }
        });
    }

    /**
     * Create user instance from database data
     *
     * @param array $data
     * @return UserInterface
     */
    private function createUserFromData(array $data): UserInterface
    {
        $modelClass = $this->config['model'] ?? \App\Models\User::class;
        
        if (class_exists($modelClass)) {
            $user = new $modelClass();
            foreach ($data as $key => $value) {
                $user->setAttribute($key, $value);
            }
            return $user;
        }

        // Fallback to generic user
        return new class($data) implements UserInterface {
            private array $attributes;

            public function __construct(array $attributes)
            {
                $this->attributes = $attributes;
            }

            public function getId()
            {
                return $this->attributes['id'] ?? null;
            }

            public function getUsername(): string
            {
                return $this->attributes['username'] ?? '';
            }

            public function getEmail(): string
            {
                return $this->attributes['email'] ?? '';
            }

            public function getPassword(): string
            {
                return $this->attributes['password'] ?? '';
            }

            public function verifyPassword(string $password): bool
            {
                return password_verify($password, $this->getPassword());
            }

            public function isActive(): bool
            {
                return ($this->attributes['status'] ?? 0) === 1;
            }

            public function getRoles(): array
            {
                return $this->attributes['roles'] ?? [];
            }

            public function getPermissions(): array
            {
                return $this->attributes['permissions'] ?? [];
            }

            public function hasRole(string $role): bool
            {
                return in_array($role, $this->getRoles());
            }

            public function hasPermission(string $permission): bool
            {
                return in_array($permission, $this->getPermissions());
            }

            public function toArray(): array
            {
                return $this->attributes;
            }
        };
    }
}