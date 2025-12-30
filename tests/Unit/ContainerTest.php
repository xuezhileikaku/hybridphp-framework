<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use HybridPHP\Core\Container;

/**
 * Container unit tests
 */
class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    public function testBindAndGet(): void
    {
        $this->container->bind('test', fn() => 'value');
        
        $this->assertTrue($this->container->has('test'));
        $this->assertEquals('value', $this->container->get('test'));
    }

    public function testSingleton(): void
    {
        $counter = 0;
        $this->container->singleton('counter', function () use (&$counter) {
            return ++$counter;
        });

        $first = $this->container->get('counter');
        $second = $this->container->get('counter');

        $this->assertEquals(1, $first);
        $this->assertEquals(1, $second);
        $this->assertSame($first, $second);
    }

    public function testInstance(): void
    {
        $object = new \stdClass();
        $object->value = 'test';

        $this->container->instance('object', $object);

        $this->assertSame($object, $this->container->get('object'));
    }

    public function testHasReturnsFalseForUnbound(): void
    {
        $this->assertFalse($this->container->has('nonexistent'));
    }

    public function testGetThrowsExceptionForUnbound(): void
    {
        $this->expectException(\Psr\Container\NotFoundExceptionInterface::class);
        $this->container->get('nonexistent');
    }

    public function testAutoWiring(): void
    {
        $this->container->bind(SimpleClass::class, SimpleClass::class);
        
        $instance = $this->container->get(SimpleClass::class);
        
        $this->assertInstanceOf(SimpleClass::class, $instance);
    }

    public function testAutoWiringWithDependencies(): void
    {
        $this->container->bind(SimpleClass::class, SimpleClass::class);
        $this->container->bind(DependentClass::class, DependentClass::class);
        
        $instance = $this->container->get(DependentClass::class);
        
        $this->assertInstanceOf(DependentClass::class, $instance);
        $this->assertInstanceOf(SimpleClass::class, $instance->dependency);
    }

    public function testInterfaceBinding(): void
    {
        $this->container->bind(TestInterface::class, TestImplementation::class);
        
        $instance = $this->container->get(TestInterface::class);
        
        $this->assertInstanceOf(TestImplementation::class, $instance);
    }

    public function testFactoryBinding(): void
    {
        $this->container->bind('factory', function (Container $c) {
            return new SimpleClass();
        });

        $instance1 = $this->container->get('factory');
        $instance2 = $this->container->get('factory');

        $this->assertInstanceOf(SimpleClass::class, $instance1);
        $this->assertNotSame($instance1, $instance2);
    }

    public function testGetInstance(): void
    {
        $instance = Container::getInstance();
        
        $this->assertInstanceOf(Container::class, $instance);
        $this->assertSame($instance, Container::getInstance());
    }
}

// Test helper classes
class SimpleClass
{
    public string $value = 'simple';
}

class DependentClass
{
    public SimpleClass $dependency;

    public function __construct(SimpleClass $dependency)
    {
        $this->dependency = $dependency;
    }
}

interface TestInterface
{
    public function test(): string;
}

class TestImplementation implements TestInterface
{
    public function test(): string
    {
        return 'implemented';
    }
}
