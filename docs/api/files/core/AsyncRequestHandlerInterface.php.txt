<?php
namespace HybridPHP\Core;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Async-compatible request handler interface
 * Compatible with AMPHP v3 fiber-based async operations
 * Extends PSR-15 RequestHandlerInterface for full compatibility
 */
interface AsyncRequestHandlerInterface extends RequestHandlerInterface
{
    /**
     * Handles a request and produces a response asynchronously.
     * In AMPHP v3, this returns ResponseInterface directly but runs in async context
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface;
}