<?php

declare(strict_types=1);

namespace Tests\Performance;

use HybridPHP\Core\Http\Response;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Benchmark handler for testing request handling performance
 */
class BenchmarkHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, [], 'OK');
    }
}
