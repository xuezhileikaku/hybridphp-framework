<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use HybridPHP\Core\Database\QueryBuilder;
use HybridPHP\Core\Database\DatabaseInterface;
use Amp\Future;
use function Amp\async;

/**
 * Database unit tests - focusing on QueryBuilder SQL generation
 */
class DatabaseTest extends TestCase
{
    private QueryBuilder $builder;
    private MockDatabase $mockDb;

    protected function setUp(): void
    {
        $this->mockDb = new MockDatabase();
        $this->builder = new QueryBuilder($this->mockDb);
    }

    public function testBasicSelect(): void
    {
        $sql = $this->builder
            ->table('users')
            ->select(['id', 'name', 'email'])
            ->toSql();

        $this->assertStringContainsString('SELECT id, name, email', $sql);
        $this->assertStringContainsString('FROM users', $sql);
    }

    public function testSelectAll(): void
    {
        $sql = $this->builder
            ->table('users')
            ->toSql();

        $this->assertStringContainsString('SELECT *', $sql);
        $this->assertStringContainsString('FROM users', $sql);
    }

    public function testWhereClause(): void
    {
        $sql = $this->builder
            ->table('users')
            ->where('status', '=', 'active')
            ->toSql();

        $this->assertStringContainsString('WHERE status = ?', $sql);
    }

    public function testMultipleWhereClauses(): void
    {
        $sql = $this->builder
            ->table('users')
            ->where('status', '=', 'active')
            ->where('role', '=', 'admin')
            ->toSql();

        $this->assertStringContainsString('WHERE status = ?', $sql);
        $this->assertStringContainsString('AND role = ?', $sql);
    }

    public function testOrWhereClause(): void
    {
        $sql = $this->builder
            ->table('users')
            ->where('status', '=', 'active')
            ->orWhere('role', '=', 'admin')
            ->toSql();

        $this->assertStringContainsString('WHERE status = ?', $sql);
        $this->assertStringContainsString('OR role = ?', $sql);
    }

    public function testWhereInClause(): void
    {
        $sql = $this->builder
            ->table('users')
            ->whereIn('id', [1, 2, 3])
            ->toSql();

        $this->assertStringContainsString('WHERE id IN (?, ?, ?)', $sql);
    }

    public function testOrderBy(): void
    {
        $sql = $this->builder
            ->table('users')
            ->orderBy('created_at', 'DESC')
            ->toSql();

        $this->assertStringContainsString('ORDER BY created_at DESC', $sql);
    }

    public function testMultipleOrderBy(): void
    {
        $sql = $this->builder
            ->table('users')
            ->orderBy('name', 'ASC')
            ->orderBy('created_at', 'DESC')
            ->toSql();

        $this->assertStringContainsString('ORDER BY name ASC, created_at DESC', $sql);
    }

    public function testGroupBy(): void
    {
        $sql = $this->builder
            ->table('orders')
            ->select(['user_id', 'COUNT(*) as total'])
            ->groupBy('user_id')
            ->toSql();

        $this->assertStringContainsString('GROUP BY user_id', $sql);
    }

    public function testLimit(): void
    {
        $sql = $this->builder
            ->table('users')
            ->limit(10)
            ->toSql();

        $this->assertStringContainsString('LIMIT 10', $sql);
    }

    public function testLimitWithOffset(): void
    {
        $sql = $this->builder
            ->table('users')
            ->limit(10)
            ->offset(20)
            ->toSql();

        $this->assertStringContainsString('LIMIT 10', $sql);
        $this->assertStringContainsString('OFFSET 20', $sql);
    }

    public function testJoin(): void
    {
        $sql = $this->builder
            ->table('users')
            ->join('orders', 'users.id', '=', 'orders.user_id')
            ->toSql();

        $this->assertStringContainsString('INNER JOIN orders ON users.id = orders.user_id', $sql);
    }

    public function testLeftJoin(): void
    {
        $sql = $this->builder
            ->table('users')
            ->leftJoin('orders', 'users.id', '=', 'orders.user_id')
            ->toSql();

        $this->assertStringContainsString('LEFT JOIN orders ON users.id = orders.user_id', $sql);
    }

    public function testComplexQuery(): void
    {
        $sql = $this->builder
            ->table('users')
            ->select(['users.id', 'users.name', 'COUNT(orders.id) as order_count'])
            ->leftJoin('orders', 'users.id', '=', 'orders.user_id')
            ->where('users.status', '=', 'active')
            ->groupBy('users.id')
            ->orderBy('order_count', 'DESC')
            ->limit(10)
            ->toSql();

        $this->assertStringContainsString('SELECT users.id, users.name, COUNT(orders.id) as order_count', $sql);
        $this->assertStringContainsString('FROM users', $sql);
        $this->assertStringContainsString('LEFT JOIN orders', $sql);
        $this->assertStringContainsString('WHERE users.status = ?', $sql);
        $this->assertStringContainsString('GROUP BY users.id', $sql);
        $this->assertStringContainsString('ORDER BY order_count DESC', $sql);
        $this->assertStringContainsString('LIMIT 10', $sql);
    }

    public function testGetBindings(): void
    {
        $this->builder
            ->table('users')
            ->where('status', '=', 'active')
            ->where('role', '=', 'admin')
            ->toSql();

        $bindings = $this->builder->getBindings();

        $this->assertCount(2, $bindings);
        $this->assertEquals('active', $bindings[0]);
        $this->assertEquals('admin', $bindings[1]);
    }

    public function testWhereInBindings(): void
    {
        $this->builder
            ->table('users')
            ->whereIn('id', [1, 2, 3])
            ->toSql();

        $bindings = $this->builder->getBindings();

        $this->assertCount(3, $bindings);
        $this->assertEquals([1, 2, 3], $bindings);
    }
}

/**
 * Mock database for testing QueryBuilder
 */
class MockDatabase implements DatabaseInterface
{
    public function query(string $sql, array $params = []): Future
    {
        return async(fn() => new MockResult());
    }

    public function execute(string $sql, array $params = []): Future
    {
        return async(fn() => 1);
    }

    public function beginTransaction(): Future
    {
        return async(fn() => true);
    }

    public function commit(): Future
    {
        return async(fn() => true);
    }

    public function rollback(): Future
    {
        return async(fn() => true);
    }

    public function transaction(callable $callback): Future
    {
        return async(fn() => $callback($this));
    }

    public function healthCheck(): Future
    {
        return async(fn() => true);
    }

    public function getStats(): array
    {
        return [];
    }
}

class MockResult
{
    private array $data = [];
    private int $position = -1;

    public function advance(): Future
    {
        return async(function () {
            $this->position++;
            return $this->position < count($this->data);
        });
    }

    public function getCurrent(): array
    {
        return $this->data[$this->position] ?? [];
    }
}
