<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use HybridPHP\Core\Routing\Router;

/**
 * Router unit tests
 */
class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    public function testAddGetRoute(): void
    {
        $this->router->get('/test', 'TestController@index');
        
        $routes = $this->router->getRoutes();
        
        $this->assertCount(1, $routes);
        $this->assertEquals('GET', $routes[0]['method']);
        $this->assertEquals('/test', $routes[0]['path']);
    }

    public function testAddPostRoute(): void
    {
        $this->router->post('/users', 'UserController@store');
        
        $routes = $this->router->getRoutes();
        
        $this->assertCount(1, $routes);
        $this->assertEquals('POST', $routes[0]['method']);
    }

    public function testAddPutRoute(): void
    {
        $this->router->put('/users/{id}', 'UserController@update');
        
        $routes = $this->router->getRoutes();
        
        $this->assertEquals('PUT', $routes[0]['method']);
    }

    public function testAddDeleteRoute(): void
    {
        $this->router->delete('/users/{id}', 'UserController@destroy');
        
        $routes = $this->router->getRoutes();
        
        $this->assertEquals('DELETE', $routes[0]['method']);
    }

    public function testRouteWithParameters(): void
    {
        $this->router->get('/users/{id}', 'UserController@show');
        
        $result = $this->router->match('GET', '/users/123');
        
        $this->assertNotNull($result);
        $this->assertEquals(['id' => '123'], $result['params']);
    }

    public function testRouteWithMultipleParameters(): void
    {
        $this->router->get('/posts/{postId}/comments/{commentId}', 'CommentController@show');
        
        $result = $this->router->match('GET', '/posts/1/comments/5');
        
        $this->assertNotNull($result);
        $this->assertEquals(['postId' => '1', 'commentId' => '5'], $result['params']);
    }

    public function testRouteGroup(): void
    {
        $this->router->group(['prefix' => '/api'], function (Router $router) {
            $router->get('/users', 'UserController@index');
            $router->get('/posts', 'PostController@index');
        });
        
        $routes = $this->router->getRoutes();
        
        $this->assertCount(2, $routes);
        $this->assertEquals('/api/users', $routes[0]['path']);
        $this->assertEquals('/api/posts', $routes[1]['path']);
    }

    public function testRouteGroupWithMiddleware(): void
    {
        $this->router->group(['middleware' => ['auth']], function (Router $router) {
            $router->get('/dashboard', 'DashboardController@index');
        });
        
        $routes = $this->router->getRoutes();
        
        $this->assertContains('auth', $routes[0]['middleware']);
    }

    public function testNestedRouteGroups(): void
    {
        $this->router->group(['prefix' => '/api'], function (Router $router) {
            $router->group(['prefix' => '/v1'], function (Router $router) {
                $router->get('/users', 'UserController@index');
            });
        });
        
        $routes = $this->router->getRoutes();
        
        $this->assertEquals('/api/v1/users', $routes[0]['path']);
    }

    public function testRouteNotFound(): void
    {
        $this->router->get('/test', 'TestController@index');
        
        $result = $this->router->match('GET', '/nonexistent');
        
        $this->assertNull($result);
    }

    public function testMethodNotAllowed(): void
    {
        $this->router->get('/test', 'TestController@index');
        
        $result = $this->router->match('POST', '/test');
        
        $this->assertNull($result);
    }

    public function testNamedRoute(): void
    {
        $this->router->get('/users/{id}', 'UserController@show')->name('users.show');
        
        $url = $this->router->route('users.show', ['id' => 123]);
        
        $this->assertEquals('/users/123', $url);
    }

    public function testResourceRoutes(): void
    {
        $this->router->resource('posts', 'PostController');
        
        $routes = $this->router->getRoutes();
        
        // Should create index, create, store, show, edit, update, destroy
        $this->assertGreaterThanOrEqual(5, count($routes));
    }

    public function testRouteWithCallableHandler(): void
    {
        $handler = function () {
            return 'response';
        };
        
        $this->router->get('/callable', $handler);
        
        $result = $this->router->match('GET', '/callable');
        
        $this->assertNotNull($result);
        $this->assertIsCallable($result['handler']);
    }

    public function testRouteWithArrayHandler(): void
    {
        $this->router->get('/array', [TestController::class, 'index']);
        
        $result = $this->router->match('GET', '/array');
        
        $this->assertNotNull($result);
        $this->assertIsArray($result['handler']);
    }
}

// Test helper class
class TestController
{
    public function index(): string
    {
        return 'index';
    }
}
