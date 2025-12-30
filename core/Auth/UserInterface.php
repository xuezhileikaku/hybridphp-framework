<?php

declare(strict_types=1);

namespace HybridPHP\Core\Auth;

/**
 * User interface for authentication
 */
interface UserInterface
{
    /**
     * Get the user's unique identifier
     *
     * @return int|string
     */
    public function getId();

    /**
     * Get the user's username
     *
     * @return string
     */
    public function getUsername(): string;

    /**
     * Get the user's email
     *
     * @return string
     */
    public function getEmail(): string;

    /**
     * Get the user's password hash
     *
     * @return string
     */
    public function getPassword(): string;

    /**
     * Verify the user's password
     *
     * @param string $password
     * @return bool
     */
    public function verifyPassword(string $password): bool;

    /**
     * Check if the user is active
     *
     * @return bool
     */
    public function isActive(): bool;

    /**
     * Get user roles
     *
     * @return array
     */
    public function getRoles(): array;

    /**
     * Get user permissions
     *
     * @return array
     */
    public function getPermissions(): array;

    /**
     * Check if user has a specific role
     *
     * @param string $role
     * @return bool
     */
    public function hasRole(string $role): bool;

    /**
     * Check if user has a specific permission
     *
     * @param string $permission
     * @return bool
     */
    public function hasPermission(string $permission): bool;

    /**
     * Get user attributes as array
     *
     * @return array
     */
    public function toArray(): array;
}