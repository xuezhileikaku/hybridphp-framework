<?php
namespace HybridPHP\Core\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use HybridPHP\Core\Http\Response;

/**
 * Async CSRF Protection Middleware
 * Protects against Cross-Site Request Forgery attacks
 */
class CsrfProtectionMiddleware extends AbstractMiddleware
{
    private array $config;
    private array $excludedPaths;
    private array $safeMethods = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'token_name' => '_token',
            'header_name' => 'X-CSRF-TOKEN',
            'cookie_name' => 'XSRF-TOKEN',
            'token_lifetime' => 3600, // 1 hour
            'regenerate_on_use' => false,
            'same_site' => 'Strict',
            'secure' => true,
            'http_only' => false, // Allow JS access for AJAX requests
        ], $config);

        $this->excludedPaths = $config['excluded_paths'] ?? ['/api/webhook'];
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();

        // Skip CSRF protection for safe methods and excluded paths
        if (in_array($method, $this->safeMethods) || $this->isPathExcluded($path)) {
            return $this->addTokenToResponse($request, $handler->handle($request));
        }

        // Validate CSRF token for unsafe methods
        if (!$this->validateCsrfToken($request)) {
            return $this->forbiddenResponse('CSRF token mismatch');
        }

        $response = $handler->handle($request);

        // Regenerate token if configured
        if ($this->config['regenerate_on_use']) {
            $response = $this->addTokenToResponse($request, $response);
        }

        return $response;
    }

    /**
     * Validate CSRF token from request
     *
     * @param ServerRequestInterface $request
     * @return bool
     */
    private function validateCsrfToken(ServerRequestInterface $request): bool
    {
        $token = $this->getTokenFromRequest($request);
        
        if (!$token) {
            return false;
        }

        $sessionToken = $this->getSessionToken($request);
        
        if (!$sessionToken) {
            return false;
        }

        return hash_equals($sessionToken, $token);
    }

    /**
     * Get CSRF token from request (header, body, or query)
     *
     * @param ServerRequestInterface $request
     * @return string|null
     */
    private function getTokenFromRequest(ServerRequestInterface $request): ?string
    {
        // Check header first
        $token = $request->getHeaderLine($this->config['header_name']);
        if ($token) {
            return $token;
        }

        // Check POST data
        $parsedBody = $request->getParsedBody();
        if (is_array($parsedBody) && isset($parsedBody[$this->config['token_name']])) {
            return $parsedBody[$this->config['token_name']];
        }

        // Check query parameters
        $queryParams = $request->getQueryParams();
        if (isset($queryParams[$this->config['token_name']])) {
            return $queryParams[$this->config['token_name']];
        }

        return null;
    }

    /**
     * Get CSRF token from session
     *
     * @param ServerRequestInterface $request
     * @return string|null
     */
    private function getSessionToken(ServerRequestInterface $request): ?string
    {
        // In a real implementation, you'd get this from your session store
        // For now, we'll use a simple approach with cookies
        $cookies = $request->getCookieParams();
        return $cookies[$this->config['cookie_name']] ?? null;
    }

    /**
     * Generate a new CSRF token
     *
     * @return string
     */
    private function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Add CSRF token to response
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    private function addTokenToResponse(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $token = $this->generateToken();
        
        // Add token as cookie
        $cookieValue = sprintf(
            '%s=%s; Max-Age=%d; Path=/; SameSite=%s%s%s',
            $this->config['cookie_name'],
            $token,
            $this->config['token_lifetime'],
            $this->config['same_site'],
            $this->config['secure'] ? '; Secure' : '',
            $this->config['http_only'] ? '; HttpOnly' : ''
        );

        return $response->withAddedHeader('Set-Cookie', $cookieValue);
    }

    /**
     * Check if path is excluded from CSRF protection
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
     * Return forbidden response
     *
     * @param string $message
     * @return ResponseInterface
     */
    private function forbiddenResponse(string $message = 'Forbidden'): ResponseInterface
    {
        return new Response(403, [
            'Content-Type' => 'application/json'
        ], json_encode([
            'error' => $message,
            'code' => 403
        ]));
    }
}