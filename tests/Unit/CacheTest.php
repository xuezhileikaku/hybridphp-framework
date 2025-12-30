<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use HybridPHP\Core\Cache\MemoryCache;
use function Amp\async;

/**
 * Cache unit tests
 */
class CacheTest extends TestCase
{
    private MemoryCache $cache;

    protected function setUp(): void
    {
        $this->cache = new MemoryCache([
            'prefix' => 'test_',
            'default_ttl' => 3600,
        ]);
    }

    public function testSetAndGet(): void
    {
        $result = async(function () {
            $this->cache->set('key', 'value')->await();
            return $this->cache->get('key')->await();
        })->await();

        $this->assertEquals('value', $result);
    }

    public function testGetWithDefault(): void
    {
        $result = async(function () {
            return $this->cache->get('nonexistent', 'default')->await();
        })->await();

        $this->assertEquals('default', $result);
    }

    public function testHas(): void
    {
        $result = async(function () {
            $this->cache->set('exists', 'value')->await();
            
            $hasExists = $this->cache->has('exists')->await();
            $hasNonexistent = $this->cache->has('nonexistent')->await();
            
            return [$hasExists, $hasNonexistent];
        })->await();

        $this->assertTrue($result[0]);
        $this->assertFalse($result[1]);
    }

    public function testDelete(): void
    {
        $result = async(function () {
            $this->cache->set('to_delete', 'value')->await();
            $this->cache->delete('to_delete')->await();
            
            return $this->cache->has('to_delete')->await();
        })->await();

        $this->assertFalse($result);
    }

    public function testClear(): void
    {
        $result = async(function () {
            $this->cache->set('key1', 'value1')->await();
            $this->cache->set('key2', 'value2')->await();
            
            $this->cache->clear()->await();
            
            $has1 = $this->cache->has('key1')->await();
            $has2 = $this->cache->has('key2')->await();
            
            return [$has1, $has2];
        })->await();

        $this->assertFalse($result[0]);
        $this->assertFalse($result[1]);
    }

    public function testIncrement(): void
    {
        $result = async(function () {
            $this->cache->set('counter', 5)->await();
            return $this->cache->increment('counter', 3)->await();
        })->await();

        $this->assertEquals(8, $result);
    }

    public function testDecrement(): void
    {
        $result = async(function () {
            $this->cache->set('counter', 10)->await();
            return $this->cache->decrement('counter', 3)->await();
        })->await();

        $this->assertEquals(7, $result);
    }

    public function testGetMultiple(): void
    {
        $result = async(function () {
            $this->cache->set('key1', 'value1')->await();
            $this->cache->set('key2', 'value2')->await();
            
            return $this->cache->getMultiple(['key1', 'key2', 'key3'])->await();
        })->await();

        $this->assertEquals('value1', $result['key1']);
        $this->assertEquals('value2', $result['key2']);
        $this->assertNull($result['key3']);
    }

    public function testSetMultiple(): void
    {
        $result = async(function () {
            $this->cache->setMultiple([
                'multi1' => 'value1',
                'multi2' => 'value2',
            ])->await();
            
            $val1 = $this->cache->get('multi1')->await();
            $val2 = $this->cache->get('multi2')->await();
            
            return [$val1, $val2];
        })->await();

        $this->assertEquals('value1', $result[0]);
        $this->assertEquals('value2', $result[1]);
    }

    public function testDeleteMultiple(): void
    {
        $result = async(function () {
            $this->cache->set('del1', 'value1')->await();
            $this->cache->set('del2', 'value2')->await();
            
            $this->cache->deleteMultiple(['del1', 'del2'])->await();
            
            $has1 = $this->cache->has('del1')->await();
            $has2 = $this->cache->has('del2')->await();
            
            return [$has1, $has2];
        })->await();

        $this->assertFalse($result[0]);
        $this->assertFalse($result[1]);
    }

    public function testCacheWithArrayValue(): void
    {
        $result = async(function () {
            $data = ['name' => 'John', 'age' => 30];
            $this->cache->set('array_data', $data)->await();
            
            return $this->cache->get('array_data')->await();
        })->await();

        $this->assertEquals(['name' => 'John', 'age' => 30], $result);
    }

    public function testCacheWithObjectValue(): void
    {
        $result = async(function () {
            $obj = new \stdClass();
            $obj->name = 'Test';
            
            $this->cache->set('object_data', $obj)->await();
            
            return $this->cache->get('object_data')->await();
        })->await();

        $this->assertEquals('Test', $result->name);
    }

    public function testGetStats(): void
    {
        $result = async(function () {
            $this->cache->set('stat_key', 'value')->await();
            return $this->cache->getStats()->await();
        })->await();

        $this->assertArrayHasKey('total_keys', $result);
        $this->assertArrayHasKey('memory_usage', $result);
        $this->assertGreaterThanOrEqual(1, $result['total_keys']);
    }
}
