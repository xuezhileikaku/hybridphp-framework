<?php
namespace HybridPHP\Core\Middleware;

use HybridPHP\Core\MiddlewareInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Abstract base class for async middleware
 * Compatible with AMPHP v3 fiber-based async operations
 */
abstract class AbstractMiddleware implements MiddlewareInterface
{
    /**
     * Process the request through this middleware
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Pre-processing
        $request = $this->before($request);
        
        // Continue to next middleware/handler
        $response = $handler->handle($request);
        
        // Post-processing
        $response = $this->after($request, $response);
        
        return $response;
    }

    /**
     * Pre-processing hook - called before the next handler
     *
     * @param ServerRequestInterface $request
     * @return ServerRequestInterface
     */
    protected function before(ServerRequestInterface $request): ServerRequestInterface
    {
        return $request;
    }

    /**
     * Post-processing hook - called after the next handler
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    protected function after(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $response;
    }
}