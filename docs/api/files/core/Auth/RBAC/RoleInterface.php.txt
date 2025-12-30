<?php

declare(strict_types=1);

namespace HybridPHP\Core\Auth\RBAC;

/**
 * Role interface for RBAC
 */
interface RoleInterface
{
    /**
     * Get role ID
     *
     * @return int|string
     */
    public function getId();

    /**
     * Get role name
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get role description
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * Get role permissions
     *
     * @return array
     */
    public function getPermissions(): array;

    /**
     * Check if role has permission
     *
     * @param string $permission
     * @return bool
     */
    public function hasPermission(string $permission): bool;

    /**
     * Add permission to role
     *
     * @param string $permission
     * @return void
     */
    public function addPermission(string $permission): void;

    /**
     * Remove permission from role
     *
     * @param string $permission
     * @return void
     */
    public function removePermission(string $permission): void;
}