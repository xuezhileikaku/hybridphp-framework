<?php

declare(strict_types=1);

namespace Tests\Performance;

use HybridPHP\Core\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Benchmark middleware for testing pipeline performance
 */
class BenchmarkMiddleware implements MiddlewareInterface
{
    private string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Add attribute to track middleware execution
        $request = $request->withAttribute("middleware_{$this->name}", true);
        
        return $handler->handle($request);
    }
}
