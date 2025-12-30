<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use HybridPHP\Core\MiddlewarePipeline;
use HybridPHP\Core\MiddlewareManager;
use HybridPHP\Core\MiddlewareInterface;
use HybridPHP\Core\Middleware\AbstractMiddleware;
use HybridPHP\Core\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware unit tests
 */
class MiddlewareTest extends TestCase
{
    private MiddlewareManager $manager;

    protected function setUp(): void
    {
        $this->manager = new MiddlewareManager();
    }

    public function testAddGlobalMiddleware(): void
    {
        $this->manager->addGlobal(TestMiddleware::class);
        
        $middleware = $this->manager->getGlobalMiddleware();
        
        $this->assertContains(TestMiddleware::class, $middleware);
    }

    public function testAddGlobalMiddlewareWithPriority(): void
    {
        $this->manager->addGlobal(TestMiddleware::class, 10);
        $this->manager->addGlobal(AnotherTestMiddleware::class, 20);
        
        $middleware = $this->manager->getGlobalMiddleware();
        
        // Higher priority should come first
        $this->assertEquals(AnotherTestMiddleware::class, $middleware[0]);
        $this->assertEquals(TestMiddleware::class, $middleware[1]);
    }

    public function testAddToGroup(): void
    {
        $this->manager->addToGroup('api', TestMiddleware::class);
        
        $middleware = $this->manager->getGroupMiddleware('api');
        
        $this->assertContains(TestMiddleware::class, $middleware);
    }

    public function testAddToRoute(): void
    {
        $this->manager->addToRoute('users.show', TestMiddleware::class);
        
        $middleware = $this->manager->getRouteMiddleware('users.show');
        
        $this->assertContains(TestMiddleware::class, $middleware);
    }

    public function testMiddlewareAlias(): void
    {
        $this->manager->alias('test', TestMiddleware::class);
        $this->manager->addGlobal('test');
        
        $middleware = $this->manager->getGlobalMiddleware();
        
        $this->assertContains(TestMiddleware::class, $middleware);
    }

    public function testRemoveGlobalMiddleware(): void
    {
        $this->manager->addGlobal(TestMiddleware::class);
        $this->manager->removeGlobal(TestMiddleware::class);
        
        $middleware = $this->manager->getGlobalMiddleware();
        
        $this->assertNotContains(TestMiddleware::class, $middleware);
    }

    public function testRemoveFromGroup(): void
    {
        $this->manager->addToGroup('api', TestMiddleware::class);
        $this->manager->removeFromGroup('api', TestMiddleware::class);
        
        $middleware = $this->manager->getGroupMiddleware('api');
        
        $this->assertNotContains(TestMiddleware::class, $middleware);
    }

    public function testClearGroup(): void
    {
        $this->manager->addToGroup('api', TestMiddleware::class);
        $this->manager->addToGroup('api', AnotherTestMiddleware::class);
        $this->manager->clearGroup('api');
        
        $middleware = $this->manager->getGroupMiddleware('api');
        
        $this->assertEmpty($middleware);
    }

    public function testClearGlobal(): void
    {
        $this->manager->addGlobal(TestMiddleware::class);
        $this->manager->clearGlobal();
        
        $middleware = $this->manager->getGlobalMiddleware();
        
        $this->assertEmpty($middleware);
    }

    public function testCreatePipeline(): void
    {
        $handler = new TestHandler();
        
        $pipeline = $this->manager->createPipeline($handler);
        
        $this->assertInstanceOf(MiddlewarePipeline::class, $pipeline);
    }

    public function testCreatePipelineWithGroups(): void
    {
        $handler = new TestHandler();
        $this->manager->addToGroup('api', TestMiddleware::class);
        
        $pipeline = $this->manager->createPipeline($handler, ['api']);
        $middleware = $pipeline->getMiddleware();
        
        $this->assertContains(TestMiddleware::class, $middleware);
    }

    public function testCreatePipelineWithRouteMiddleware(): void
    {
        $handler = new TestHandler();
        
        $pipeline = $this->manager->createPipeline(
            $handler,
            [],
            null,
            [TestMiddleware::class]
        );
        $middleware = $pipeline->getMiddleware();
        
        $this->assertContains(TestMiddleware::class, $middleware);
    }

    public function testGetEmptyGroupMiddleware(): void
    {
        $middleware = $this->manager->getGroupMiddleware('nonexistent');
        
        $this->assertEmpty($middleware);
    }

    public function testGetEmptyRouteMiddleware(): void
    {
        $middleware = $this->manager->getRouteMiddleware('nonexistent');
        
        $this->assertEmpty($middleware);
    }
}

// Test helper classes
class TestMiddleware extends AbstractMiddleware
{
    protected function before(ServerRequestInterface $request): ServerRequestInterface
    {
        return $request->withAttribute('test_middleware', true);
    }
}

class AnotherTestMiddleware extends AbstractMiddleware
{
    protected function before(ServerRequestInterface $request): ServerRequestInterface
    {
        return $request->withAttribute('another_middleware', true);
    }
}

class TestHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200, [], 'OK');
    }
}
