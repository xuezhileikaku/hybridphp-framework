<?php
namespace HybridPHP\Core;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface as PsrMiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Async middleware interface extending PSR-15
 * All middleware must be coroutine-compatible for async operations
 */
interface MiddlewareInterface extends PsrMiddlewareInterface
{
    /**
     * Process an incoming server request and return a response, asynchronously.
     * In AMPHP v3, this returns ResponseInterface directly but runs in async context
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface;
}
