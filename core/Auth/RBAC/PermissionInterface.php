<?php

declare(strict_types=1);

namespace HybridPHP\Core\Auth\RBAC;

/**
 * Permission interface for RBAC
 */
interface PermissionInterface
{
    /**
     * Get permission ID
     *
     * @return int|string
     */
    public function getId();

    /**
     * Get permission name
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get permission description
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * Get permission resource
     *
     * @return string
     */
    public function getResource(): string;

    /**
     * Get permission action
     *
     * @return string
     */
    public function getAction(): string;

    /**
     * Check if permission matches resource and action
     *
     * @param string $resource
     * @param string $action
     * @return bool
     */
    public function matches(string $resource, string $action): bool;
}