<?php

declare(strict_types=1);

namespace HybridPHP\Core\Auth\RBAC;

use Amp\Future;
use HybridPHP\Core\Auth\UserInterface;
use HybridPHP\Core\Cache\CacheInterface;
use HybridPHP\Core\Database\DatabaseInterface;
use function Amp\async;

/**
 * RBAC (Role-Based Access Control) Manager
 */
class RBACManager
{
    private DatabaseInterface $db;
    private ?CacheInterface $cache;
    private array $config;
    private array $roleCache = [];
    private array $permissionCache = [];

    public function __construct(DatabaseInterface $db, array $config, ?CacheInterface $cache = null)
    {
        $this->db = $db;
        $this->config = $config;
        $this->cache = $cache;
    }

    /**
     * Check if user has permission
     */
    public function hasPermission(UserInterface $user, string $permission, ?string $resource = null): Future
    {
        return async(function () use ($user, $permission, $resource) {
            $userPermissions = $this->getUserPermissions($user)->await();
            
            if (in_array($permission, $userPermissions)) {
                return true;
            }

            if ($resource) {
                $resourcePermission = "{$resource}.{$permission}";
                if (in_array($resourcePermission, $userPermissions)) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * Check if user has role
     */
    public function hasRole(UserInterface $user, string $role): Future
    {
        return async(function () use ($user, $role) {
            $userRoles = $this->getUserRoles($user)->await();
            return in_array($role, array_column($userRoles, 'name'));
        });
    }

    /**
     * Get user roles
     */
    public function getUserRoles(UserInterface $user): Future
    {
        return async(function () use ($user) {
            $cacheKey = "user_roles:{$user->getId()}";
            
            if ($this->cache && $this->config['cache_permissions']) {
                $cached = $this->cache->get($cacheKey)->await();
                if ($cached !== null) {
                    return $cached;
                }
            }

            $roles = $this->db->query(
                "SELECT r.* FROM roles r 
                 INNER JOIN user_roles ur ON r.id = ur.role_id 
                 WHERE ur.user_id = ?",
                [$user->getId()]
            )->await();

            if ($this->cache && $this->config['cache_permissions']) {
                $this->cache->set($cacheKey, $roles, $this->config['cache_ttl'])->await();
            }

            return $roles;
        });
    }

    /**
     * Get user permissions
     */
    public function getUserPermissions(UserInterface $user): Future
    {
        return async(function () use ($user) {
            $cacheKey = "user_permissions:{$user->getId()}";
            
            if ($this->cache && $this->config['cache_permissions']) {
                $cached = $this->cache->get($cacheKey)->await();
                if ($cached !== null) {
                    return $cached;
                }
            }

            $permissions = $this->db->query(
                "SELECT DISTINCT p.name FROM permissions p
                 INNER JOIN role_permissions rp ON p.id = rp.permission_id
                 INNER JOIN user_roles ur ON rp.role_id = ur.role_id
                 WHERE ur.user_id = ?",
                [$user->getId()]
            )->await();

            $directPermissions = $this->db->query(
                "SELECT p.name FROM permissions p
                 INNER JOIN user_permissions up ON p.id = up.permission_id
                 WHERE up.user_id = ?",
                [$user->getId()]
            )->await();

            $allPermissions = array_merge(
                array_column($permissions, 'name'),
                array_column($directPermissions, 'name')
            );

            $allPermissions = array_unique($allPermissions);

            if ($this->cache && $this->config['cache_permissions']) {
                $this->cache->set($cacheKey, $allPermissions, $this->config['cache_ttl'])->await();
            }

            return $allPermissions;
        });
    }

    /**
     * Assign role to user
     */
    public function assignRole(UserInterface $user, string $roleName): Future
    {
        return async(function () use ($user, $roleName) {
            $role = $this->getRoleByName($roleName)->await();
            if (!$role) {
                return false;
            }

            $existing = $this->db->query(
                "SELECT id FROM user_roles WHERE user_id = ? AND role_id = ?",
                [$user->getId(), $role['id']]
            )->await();

            if (!empty($existing)) {
                return true;
            }

            $this->db->execute(
                "INSERT INTO user_roles (user_id, role_id, created_at) VALUES (?, ?, ?)",
                [$user->getId(), $role['id'], date('Y-m-d H:i:s')]
            )->await();

            $this->clearUserCache($user)->await();

            return true;
        });
    }

    /**
     * Remove role from user
     */
    public function removeRole(UserInterface $user, string $roleName): Future
    {
        return async(function () use ($user, $roleName) {
            $role = $this->getRoleByName($roleName)->await();
            if (!$role) {
                return false;
            }

            $this->db->execute(
                "DELETE FROM user_roles WHERE user_id = ? AND role_id = ?",
                [$user->getId(), $role['id']]
            )->await();

            $this->clearUserCache($user)->await();

            return true;
        });
    }

    /**
     * Grant permission to user
     */
    public function grantPermission(UserInterface $user, string $permissionName): Future
    {
        return async(function () use ($user, $permissionName) {
            $permission = $this->getPermissionByName($permissionName)->await();
            if (!$permission) {
                return false;
            }

            $existing = $this->db->query(
                "SELECT id FROM user_permissions WHERE user_id = ? AND permission_id = ?",
                [$user->getId(), $permission['id']]
            )->await();

            if (!empty($existing)) {
                return true;
            }

            $this->db->execute(
                "INSERT INTO user_permissions (user_id, permission_id, created_at) VALUES (?, ?, ?)",
                [$user->getId(), $permission['id'], date('Y-m-d H:i:s')]
            )->await();

            $this->clearUserCache($user)->await();

            return true;
        });
    }

    /**
     * Revoke permission from user
     */
    public function revokePermission(UserInterface $user, string $permissionName): Future
    {
        return async(function () use ($user, $permissionName) {
            $permission = $this->getPermissionByName($permissionName)->await();
            if (!$permission) {
                return false;
            }

            $this->db->execute(
                "DELETE FROM user_permissions WHERE user_id = ? AND permission_id = ?",
                [$user->getId(), $permission['id']]
            )->await();

            $this->clearUserCache($user)->await();

            return true;
        });
    }

    /**
     * Create a new role
     */
    public function createRole(string $name, string $description = '', array $permissions = []): Future
    {
        return async(function () use ($name, $description, $permissions) {
            $existing = $this->getRoleByName($name)->await();
            if ($existing) {
                return false;
            }

            $roleId = $this->db->execute(
                "INSERT INTO roles (name, description, created_at) VALUES (?, ?, ?)",
                [$name, $description, date('Y-m-d H:i:s')]
            )->await();

            foreach ($permissions as $permissionName) {
                $permission = $this->getPermissionByName($permissionName)->await();
                if ($permission) {
                    $this->db->execute(
                        "INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)",
                        [$roleId, $permission['id']]
                    )->await();
                }
            }

            return true;
        });
    }

    /**
     * Create a new permission
     */
    public function createPermission(string $name, string $description = '', string $resource = '', string $action = ''): Future
    {
        return async(function () use ($name, $description, $resource, $action) {
            $existing = $this->getPermissionByName($name)->await();
            if ($existing) {
                return false;
            }

            $this->db->execute(
                "INSERT INTO permissions (name, description, resource, action, created_at) VALUES (?, ?, ?, ?, ?)",
                [$name, $description, $resource, $action, date('Y-m-d H:i:s')]
            )->await();

            return true;
        });
    }

    /**
     * Get role by name
     */
    private function getRoleByName(string $name): Future
    {
        return async(function () use ($name) {
            if (isset($this->roleCache[$name])) {
                return $this->roleCache[$name];
            }

            $result = $this->db->query(
                "SELECT * FROM roles WHERE name = ? LIMIT 1",
                [$name]
            )->await();

            $role = !empty($result) ? $result[0] : null;
            $this->roleCache[$name] = $role;

            return $role;
        });
    }

    /**
     * Get permission by name
     */
    private function getPermissionByName(string $name): Future
    {
        return async(function () use ($name) {
            if (isset($this->permissionCache[$name])) {
                return $this->permissionCache[$name];
            }

            $result = $this->db->query(
                "SELECT * FROM permissions WHERE name = ? LIMIT 1",
                [$name]
            )->await();

            $permission = !empty($result) ? $result[0] : null;
            $this->permissionCache[$name] = $permission;

            return $permission;
        });
    }

    /**
     * Clear user cache
     */
    private function clearUserCache(UserInterface $user): Future
    {
        return async(function () use ($user) {
            if ($this->cache) {
                $this->cache->delete("user_roles:{$user->getId()}")->await();
                $this->cache->delete("user_permissions:{$user->getId()}")->await();
            }
        });
    }
}
