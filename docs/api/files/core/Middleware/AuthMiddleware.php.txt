<?php
namespace HybridPHP\Core\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use HybridPHP\Core\Http\Response;

/**
 * Async authentication middleware
 * Handles JWT, session, and API key authentication
 */
class AuthMiddleware extends AbstractMiddleware
{
    private array $config;
    private array $excludedPaths;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'auth_type' => 'jwt', // jwt, session, api_key
            'jwt_secret' => null,
            'jwt_algorithm' => 'HS256',
            'session_name' => 'PHPSESSID',
            'api_key_header' => 'X-API-Key',
            'unauthorized_message' => 'Unauthorized',
        ], $config);

        $this->excludedPaths = $config['excluded_paths'] ?? ['/login', '/register', '/health'];
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        
        // Skip authentication for excluded paths
        if ($this->isPathExcluded($path)) {
            return $handler->handle($request);
        }

        // Authenticate the request
        $user = $this->authenticate($request);
        
        if ($user === null) {
            return $this->unauthorizedResponse();
        }

        // Add user to request attributes
        $request = $request->withAttribute('user', $user);
        
        return $handler->handle($request);
    }

    /**
     * Authenticate the request based on configured auth type
     *
     * @param ServerRequestInterface $request
     * @return array|null
     */
    private function authenticate(ServerRequestInterface $request): ?array
    {
        switch ($this->config['auth_type']) {
            case 'jwt':
                return $this->authenticateJwt($request);
            case 'session':
                return $this->authenticateSession($request);
            case 'api_key':
                return $this->authenticateApiKey($request);
            default:
                return null;
        }
    }

    /**
     * Authenticate using JWT token
     *
     * @param ServerRequestInterface $request
     * @return array|null
     */
    private function authenticateJwt(ServerRequestInterface $request): ?array
    {
        $authHeader = $request->getHeaderLine('Authorization');
        
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        $token = substr($authHeader, 7);
        
        try {
            // Simple JWT validation (in production, use a proper JWT library)
            $payload = $this->validateJwtToken($token);
            return $payload;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Authenticate using session
     *
     * @param ServerRequestInterface $request
     * @return array|null
     */
    private function authenticateSession(ServerRequestInterface $request): ?array
    {
        $cookies = $request->getCookieParams();
        $sessionId = $cookies[$this->config['session_name']] ?? null;
        
        if (!$sessionId) {
            return null;
        }

        // In a real implementation, you'd validate the session with your session store
        // For now, we'll return a mock user
        return ['id' => 1, 'username' => 'user', 'session_id' => $sessionId];
    }

    /**
     * Authenticate using API key
     *
     * @param ServerRequestInterface $request
     * @return array|null
     */
    private function authenticateApiKey(ServerRequestInterface $request): ?array
    {
        $apiKey = $request->getHeaderLine($this->config['api_key_header']);
        
        if (!$apiKey) {
            return null;
        }

        // In a real implementation, you'd validate the API key against your database
        // For now, we'll return a mock user for demo purposes
        if ($apiKey === 'demo-api-key') {
            return ['id' => 1, 'username' => 'api_user', 'api_key' => $apiKey];
        }

        return null;
    }

    /**
     * Simple JWT token validation (use a proper JWT library in production)
     *
     * @param string $token
     * @return array
     * @throws \Exception
     */
    private function validateJwtToken(string $token): array
    {
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            throw new \Exception('Invalid JWT token format');
        }

        [$header, $payload, $signature] = $parts;
        
        // Decode payload
        $decodedPayload = json_decode(base64_decode($payload), true);
        
        if (!$decodedPayload) {
            throw new \Exception('Invalid JWT payload');
        }

        // Check expiration
        if (isset($decodedPayload['exp']) && $decodedPayload['exp'] < time()) {
            throw new \Exception('JWT token expired');
        }

        return $decodedPayload;
    }

    /**
     * Check if path is excluded from authentication
     *
     * @param string $path
     * @return bool
     */
    private function isPathExcluded(string $path): bool
    {
        foreach ($this->excludedPaths as $excludedPath) {
            if (str_starts_with($path, $excludedPath)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Return unauthorized response
     *
     * @return ResponseInterface
     */
    private function unauthorizedResponse(): ResponseInterface
    {
        return new Response(401, [
            'Content-Type' => 'application/json'
        ], json_encode([
            'error' => $this->config['unauthorized_message'],
            'code' => 401
        ]));
    }
}