<?php
namespace HybridPHP\Core\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use HybridPHP\Core\Http\Response;

/**
 * Async CORS middleware
 * Handles Cross-Origin Resource Sharing headers
 */
class CorsMiddleware extends AbstractMiddleware
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'allowed_origins' => ['*'],
            'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH'],
            'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
            'exposed_headers' => [],
            'max_age' => 86400,
            'credentials' => false,
        ], $config);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        
        // Handle preflight OPTIONS request
        if ($request->getMethod() === 'OPTIONS') {
            return $this->handlePreflight($request, $origin);
        }

        // Process the request normally
        $response = $handler->handle($request);
        
        // Add CORS headers to the response
        return $this->addCorsHeaders($response, $origin);
    }

    /**
     * Handle preflight OPTIONS request
     *
     * @param ServerRequestInterface $request
     * @param string $origin
     * @return ResponseInterface
     */
    private function handlePreflight(ServerRequestInterface $request, string $origin): ResponseInterface
    {
        $headers = [];
        
        if ($this->isOriginAllowed($origin)) {
            $headers['Access-Control-Allow-Origin'] = $origin;
            
            if ($this->config['credentials']) {
                $headers['Access-Control-Allow-Credentials'] = 'true';
            }
        }

        $headers['Access-Control-Allow-Methods'] = implode(', ', $this->config['allowed_methods']);
        $headers['Access-Control-Allow-Headers'] = implode(', ', $this->config['allowed_headers']);
        $headers['Access-Control-Max-Age'] = (string) $this->config['max_age'];

        return new Response(204, $headers, '');
    }

    /**
     * Add CORS headers to response
     *
     * @param ResponseInterface $response
     * @param string $origin
     * @return ResponseInterface
     */
    private function addCorsHeaders(ResponseInterface $response, string $origin): ResponseInterface
    {
        if (!$this->isOriginAllowed($origin)) {
            return $response;
        }

        $response = $response->withHeader('Access-Control-Allow-Origin', $origin);
        
        if ($this->config['credentials']) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        if (!empty($this->config['exposed_headers'])) {
            $response = $response->withHeader(
                'Access-Control-Expose-Headers',
                implode(', ', $this->config['exposed_headers'])
            );
        }

        return $response;
    }

    /**
     * Check if origin is allowed
     *
     * @param string $origin
     * @return bool
     */
    private function isOriginAllowed(string $origin): bool
    {
        if (empty($origin)) {
            return false;
        }

        if (in_array('*', $this->config['allowed_origins'])) {
            return true;
        }

        return in_array($origin, $this->config['allowed_origins']);
    }
}