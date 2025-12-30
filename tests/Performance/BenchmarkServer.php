<?php

declare(strict_types=1);

namespace Tests\Performance;

use HybridPHP\Core\Http\Request;
use HybridPHP\Core\Http\Response;
use HybridPHP\Core\Routing\Router;
use function Amp\async;

/**
 * Minimal benchmark server for load testing
 * 
 * This server provides simple endpoints for benchmarking HTTP performance
 */
class BenchmarkServer
{
    private Router $router;
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'host' => '127.0.0.1',
            'port' => 8080,
        ], $config);

        $this->router = new Router();
        $this->setupRoutes();
    }

    /**
     * Setup benchmark routes
     */
    private function setupRoutes(): void
    {
        // Simple hello world
        $this->router->get('/', function () {
            return new Response(200, ['Content-Type' => 'text/plain'], 'Hello, World!');
        });

        // Health check
        $this->router->get('/api/health', function () {
            return Response::json([
                'status' => 'healthy',
                'timestamp' => time(),
            ]);
        });

        // JSON response with data
        $this->router->get('/api/users', function () {
            $users = array_map(fn($i) => [
                'id' => $i,
                'name' => "User {$i}",
                'email' => "user{$i}@example.com",
            ], range(1, 10));

            return Response::json([
                'data' => $users,
                'meta' => [
                    'total' => 100,
                    'page' => 1,
                    'per_page' => 10,
                ],
            ]);
        });

        // User by ID
        $this->router->get('/api/users/{id}', function (Request $request) {
            $id = $request->getAttribute('id');
            return Response::json([
                'id' => (int) $id,
                'name' => "User {$id}",
                'email' => "user{$id}@example.com",
            ]);
        });

        // Create user
        $this->router->post('/api/users', function (Request $request) {
            $data = json_decode($request->getBody()->getContents(), true);
            return Response::json([
                'id' => rand(1000, 9999),
                'name' => $data['name'] ?? 'Unknown',
                'email' => $data['email'] ?? 'unknown@example.com',
                'created_at' => date('c'),
            ], 201);
        });

        // Async endpoint
        $this->router->get('/api/async', function () {
            return async(function () {
                // Simulate async work
                $results = [];
                for ($i = 0; $i < 5; $i++) {
                    $results[] = async(fn() => ['task' => $i, 'result' => $i * 2]);
                }

                $data = array_map(fn($f) => $f->await(), $results);
                return Response::json(['results' => $data]);
            })->await();
        });

        // Heavy computation endpoint
        $this->router->get('/api/compute', function () {
            $result = 0;
            for ($i = 0; $i < 1000; $i++) {
                $result += sqrt($i) * sin($i);
            }
            return Response::json(['result' => $result]);
        });

        // Large response
        $this->router->get('/api/large', function () {
            $data = array_map(fn($i) => [
                'id' => $i,
                'uuid' => bin2hex(random_bytes(16)),
                'data' => str_repeat('x', 100),
                'nested' => [
                    'a' => $i,
                    'b' => $i * 2,
                    'c' => ['d' => $i * 3],
                ],
            ], range(1, 100));

            return Response::json(['data' => $data]);
        });
    }

    /**
     * Handle request
     */
    public function handle(Request $request): Response
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        $match = $this->router->match($method, $path);

        if ($match === null) {
            return Response::json(['error' => 'Not Found'], 404);
        }

        // Add route parameters to request
        foreach ($match['params'] ?? [] as $key => $value) {
            $request = $request->withAttribute($key, $value);
        }

        $handler = $match['handler'];

        if (is_callable($handler)) {
            return $handler($request);
        }

        return Response::json(['error' => 'Invalid handler'], 500);
    }

    /**
     * Get router for testing
     */
    public function getRouter(): Router
    {
        return $this->router;
    }
}
