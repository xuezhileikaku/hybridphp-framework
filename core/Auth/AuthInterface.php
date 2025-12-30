<?php

declare(strict_types=1);

namespace HybridPHP\Core\Auth;

use Amp\Future;

/**
 * Authentication interface
 */
interface AuthInterface
{
    /**
     * Attempt to authenticate a user
     *
     * @param array $credentials
     * @return Promise<UserInterface|null>
     */
    public function attempt(array $credentials): Promise;

    /**
     * Login a user
     *
     * @param UserInterface $user
     * @param bool $remember
     * @return Promise<string|bool>
     */
    public function login(UserInterface $user, bool $remember = false): Promise;

    /**
     * Logout the current user
     *
     * @return Promise<bool>
     */
    public function logout(): Promise;

    /**
     * Get the currently authenticated user
     *
     * @return Promise<UserInterface|null>
     */
    public function user(): Promise;

    /**
     * Check if a user is authenticated
     *
     * @return Promise<bool>
     */
    public function check(): Promise;

    /**
     * Get the user ID
     *
     * @return Promise<int|string|null>
     */
    public function id(): Promise;

    /**
     * Validate a token
     *
     * @param string $token
     * @return Promise<UserInterface|null>
     */
    public function validateToken(string $token): Promise;

    /**
     * Refresh a token
     *
     * @param string $token
     * @return Promise<string|null>
     */
    public function refreshToken(string $token): Promise;
}