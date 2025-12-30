<?php
namespace HybridPHP\Core;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware pipeline implementing the onion model for async middleware processing
 * Supports global, route group, and single route middleware
 * Compatible with AMPHP v3 fiber-based async operations
 */
class MiddlewarePipeline implements AsyncRequestHandlerInterface
{
    private array $middleware = [];
    private $coreHandler;

    public function __construct($coreHandler)
    {
        $this->coreHandler = $coreHandler;
    }

    /**
     * Add middleware to the pipeline
     *
     * @param MiddlewareInterface|string $middleware
     * @return self
     */
    public function through($middleware): self
    {
        if (is_array($middleware)) {
            foreach ($middleware as $m) {
                $this->middleware[] = $m;
            }
        } else {
            $this->middleware[] = $middleware;
        }
        
        return $this;
    }

    /**
     * Add global middleware
     *
     * @param MiddlewareInterface|string $middleware
     * @return self
     */
    public function addGlobal($middleware): self
    {
        array_unshift($this->middleware, $middleware);
        return $this;
    }

    /**
     * Process the request through the middleware pipeline
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handler = $this->createHandler(0);
        return $handler->handle($request);
    }

    /**
     * Create a handler for the middleware at the given index
     *
     * @param int $index
     * @return AsyncRequestHandlerInterface
     */
    private function createHandler(int $index): AsyncRequestHandlerInterface
    {
        // If we've processed all middleware, return a wrapper for the core handler
        if ($index >= count($this->middleware)) {
            return $this->wrapHandler($this->coreHandler);
        }

        return new class($this->middleware[$index], $this->createHandler($index + 1)) implements AsyncRequestHandlerInterface {
            private $middleware;
            private AsyncRequestHandlerInterface $nextHandler;

            public function __construct($middleware, AsyncRequestHandlerInterface $nextHandler)
            {
                $this->middleware = $middleware;
                $this->nextHandler = $nextHandler;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                // Resolve middleware if it's a string (class name)
                if (is_string($this->middleware)) {
                    $this->middleware = new $this->middleware();
                }

                // Ensure middleware implements our interface
                if (!$this->middleware instanceof MiddlewareInterface) {
                    throw new \InvalidArgumentException(
                        'Middleware must implement ' . MiddlewareInterface::class
                    );
                }

                return $this->middleware->process($request, $this->nextHandler);
            }
        };
    }

    /**
     * Wrap a handler to make it async-compatible
     *
     * @param mixed $handler
     * @return AsyncRequestHandlerInterface
     */
    private function wrapHandler($handler): AsyncRequestHandlerInterface
    {
        if ($handler instanceof AsyncRequestHandlerInterface) {
            return $handler;
        }

        return new class($handler) implements AsyncRequestHandlerInterface {
            private $handler;

            public function __construct($handler)
            {
                $this->handler = $handler;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                if ($this->handler instanceof RequestHandlerInterface) {
                    return $this->handler->handle($request);
                } elseif (is_callable($this->handler)) {
                    return call_user_func($this->handler, $request);
                } else {
                    throw new \InvalidArgumentException('Handler must be callable or implement RequestHandlerInterface');
                }
            }
        };
    }

    /**
     * Create a new pipeline with additional middleware
     *
     * @param array $middleware
     * @return self
     */
    public function withMiddleware(array $middleware): self
    {
        $pipeline = clone $this;
        $pipeline->middleware = array_merge($this->middleware, $middleware);
        return $pipeline;
    }

    /**
     * Get all middleware in the pipeline
     *
     * @return array
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Clear all middleware from the pipeline
     *
     * @return self
     */
    public function clear(): self
    {
        $this->middleware = [];
        return $this;
    }
}