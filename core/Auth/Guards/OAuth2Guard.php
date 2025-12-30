<?php

declare(strict_types=1);

namespace HybridPHP\Core\Auth\Guards;

use Amp\Future;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use HybridPHP\Core\Auth\AuthInterface;
use HybridPHP\Core\Auth\UserInterface;
use HybridPHP\Core\Auth\UserProviderInterface;
use function Amp\async;

/**
 * OAuth2 authentication guard
 */
class OAuth2Guard implements AuthInterface
{
    private UserProviderInterface $provider;
    private array $config;
    private ?UserInterface $user = null;
    private ?HttpClient $httpClient = null;

    public function __construct(UserProviderInterface $provider, array $config)
    {
        $this->provider = $provider;
        $this->config = $config;
        $this->httpClient = new HttpClient();
    }

    /**
     * Attempt to authenticate a user
     *
     * @param array $credentials
     * @return Future<UserInterface|null>
     */
    public function attempt(array $credentials): Future
    {
        return async(function () use ($credentials) {
            // OAuth2 typically doesn't use direct credential validation
            // Instead, it uses authorization codes or access tokens
            if (isset($credentials['access_token'])) {
                return $this->validateToken($credentials['access_token'])->await();
            }

            return null;
        });
    }

    /**
     * Login a user (OAuth2 doesn't directly login, but we can store user info)
     *
     * @param UserInterface $user
     * @param bool $remember
     * @return Promise<bool>
     */
    public function login(UserInterface $user, bool $remember = false): Promise
    {
        return async(function () use ($user) {
            $this->user = $user;
            return true;
        });
    }

    /**
     * Logout the current user
     *
     * @return Promise<bool>
     */
    public function logout(): Promise
    {
        return async(function () {
            $this->user = null;
            return true;
        });
    }

    /**
     * Get the currently authenticated user
     *
     * @return Promise<UserInterface|null>
     */
    public function user(): Promise
    {
        return async(function () {
            return $this->user;
        });
    }

    /**
     * Check if a user is authenticated
     *
     * @return Promise<bool>
     */
    public function check(): Promise
    {
        return async(function () {
            return $this->user !== null;
        });
    }

    /**
     * Get the user ID
     *
     * @return Promise<int|string|null>
     */
    public function id(): Promise
    {
        return async(function () {
            return $this->user?->getId();
        });
    }

    /**
     * Validate an OAuth2 access token
     *
     * @param string $token
     * @return Future<UserInterface|null>
     */
    public function validateToken(string $token): Future
    {
        return async(function () use ($token) {
            try {
                // Validate token with OAuth2 provider
                $userInfo = $this->fetchUserInfo($token)->await();
                
                if (!$userInfo) {
                    return null;
                }

                // Try to find user by email or create new one
                $user = $this->provider->retrieveByCredentials(['email' => $userInfo['email']])->await();
                
                if ($user && $user->isActive()) {
                    $this->user = $user;
                    return $user;
                }

                return null;
            } catch (\Exception $e) {
                return null;
            }
        });
    }

    /**
     * Refresh an OAuth2 token
     *
     * @param string $token
     * @return Future<string|null>
     */
    public function refreshToken(string $token): Future
    {
        return async(function () use ($token) {
            try {
                $request = new Request('POST', $this->getTokenEndpoint());
                $request->setHeader('Content-Type', 'application/x-www-form-urlencoded');
                $request->setBody(http_build_query([
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $token,
                    'client_id' => $this->config['client_id'],
                    'client_secret' => $this->config['client_secret'],
                ]));

                $response = $this->httpClient->request($request)->await();
                $body = $response->getBody()->buffer()->await();
                $data = json_decode($body, true);

                return $data['access_token'] ?? null;
            } catch (\Exception $e) {
                return null;
            }
        });
    }

    /**
     * Get OAuth2 authorization URL
     *
     * @param string $state
     * @return string
     */
    public function getAuthorizationUrl(string $state = ''): string
    {
        $params = [
            'response_type' => 'code',
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'scope' => $this->config['scope'],
            'state' => $state ?: bin2hex(random_bytes(16)),
        ];

        return $this->getAuthorizationEndpoint() . '?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for access token
     *
     * @param string $code
     * @return Future<array|null>
     */
    public function exchangeCodeForToken(string $code): Future
    {
        return async(function () use ($code) {
            try {
                $request = new Request('POST', $this->getTokenEndpoint());
                $request->setHeader('Content-Type', 'application/x-www-form-urlencoded');
                $request->setBody(http_build_query([
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->config['redirect_uri'],
                    'client_id' => $this->config['client_id'],
                    'client_secret' => $this->config['client_secret'],
                ]));

                $response = $this->httpClient->request($request)->await();
                $body = $response->getBody()->buffer()->await();
                
                return json_decode($body, true);
            } catch (\Exception $e) {
                return null;
            }
        });
    }

    /**
     * Fetch user info from OAuth2 provider
     *
     * @param string $accessToken
     * @return Future<array|null>
     */
    private function fetchUserInfo(string $accessToken): Future
    {
        return async(function () use ($accessToken) {
            try {
                $request = new Request('GET', $this->getUserInfoEndpoint());
                $request->setHeader('Authorization', 'Bearer ' . $accessToken);

                $response = $this->httpClient->request($request)->await();
                $body = $response->getBody()->buffer()->await();
                
                return json_decode($body, true);
            } catch (\Exception $e) {
                return null;
            }
        });
    }

    /**
     * Get authorization endpoint URL
     *
     * @return string
     */
    private function getAuthorizationEndpoint(): string
    {
        return $this->config['authorization_endpoint'] ?? 'https://oauth2.provider.com/authorize';
    }

    /**
     * Get token endpoint URL
     *
     * @return string
     */
    private function getTokenEndpoint(): string
    {
        return $this->config['token_endpoint'] ?? 'https://oauth2.provider.com/token';
    }

    /**
     * Get user info endpoint URL
     *
     * @return string
     */
    private function getUserInfoEndpoint(): string
    {
        return $this->config['userinfo_endpoint'] ?? 'https://oauth2.provider.com/userinfo';
    }
}