<?php

declare(strict_types=1);

namespace HybridPHP\Core\Auth;

use Amp\Future;

/**
 * User provider interface
 */
interface UserProviderInterface
{
    /**
     * Retrieve a user by their unique identifier
     *
     * @param mixed $identifier
     * @return Future<UserInterface|null>
     */
    public function retrieveById($identifier): Future;

    /**
     * Retrieve a user by their unique identifier and "remember me" token
     *
     * @param mixed $identifier
     * @param string $token
     * @return Future<UserInterface|null>
     */
    public function retrieveByToken($identifier, string $token): Future;

    /**
     * Update the "remember me" token for the given user
     *
     * @param UserInterface $user
     * @param string $token
     * @return Future<void>
     */
    public function updateRememberToken(UserInterface $user, string $token): Future;

    /**
     * Retrieve a user by the given credentials
     *
     * @param array $credentials
     * @return Future<UserInterface|null>
     */
    public function retrieveByCredentials(array $credentials): Future;

    /**
     * Validate a user against the given credentials
     *
     * @param UserInterface $user
     * @param array $credentials
     * @return Future<bool>
     */
    public function validateCredentials(UserInterface $user, array $credentials): Future;

    /**
     * Rehash the user's password if required and supported
     *
     * @param UserInterface $user
     * @param array $credentials
     * @param bool $force
     * @return Future<void>
     */
    public function rehashPasswordIfRequired(UserInterface $user, array $credentials, bool $force = false): Future;
}