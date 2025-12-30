<?php

declare(strict_types=1);

namespace HybridPHP\Core\Tracing\Middleware;

use HybridPHP\Core\Tracing\SpanKind;
use HybridPHP\Core\Tracing\SpanStatus;
use HybridPHP\Core\Tracing\Tracer;

/**
 * Database query tracing middleware
 * 
 * Automatically creates spans for database operations
 */
class DatabaseTracingMiddleware
{
    private Tracer $tracer;
    private array $config;

    public function __construct(Tracer $tracer, array $config = [])
    {
        $this->tracer = $tracer;
        $this->config = array_merge([
            'record_statement' => true,
            'record_parameters' => false, // Disabled by default for security
            'max_statement_length' => 2048,
        ], $config);
    }

    /**
     * Trace a database query
     */
    public function traceQuery(
        string $operation,
        string $statement,
        array $parameters = [],
        ?string $database = null,
        ?callable $callback = null
    ): mixed {
        $span = $this->tracer->startSpan($operation);

        // Set database semantic conventions
        $attributes = [
            'db.system' => 'mysql', // or postgresql, etc.
            'db.operation' => $this->extractOperation($statement),
        ];

        if ($database !== null) {
            $attributes['db.name'] = $database;
        }

        if ($this->config['record_statement']) {
            $attributes['db.statement'] = $this->truncateStatement($statement);
        }

        if ($this->config['record_parameters'] && !empty($parameters)) {
            $attributes['db.parameters'] = json_encode($this->sanitizeParameters($parameters));
        }

        $span->setAttributes($attributes);

        try {
            if ($callback !== null) {
                $result = $callback();
                $span->setStatus(SpanStatus::OK);
                return $result;
            }
            
            $span->setStatus(SpanStatus::OK);
            return null;

        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(SpanStatus::ERROR, $e->getMessage());
            throw $e;

        } finally {
            $span->end();
        }
    }

    /**
     * Trace a database transaction
     */
    public function traceTransaction(callable $callback): mixed
    {
        $span = $this->tracer->startSpan('db.transaction');
        $span->setAttribute('db.operation', 'transaction');

        try {
            $result = $callback();
            $span->setStatus(SpanStatus::OK);
            $span->addEvent('transaction.commit');
            return $result;

        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(SpanStatus::ERROR, $e->getMessage());
            $span->addEvent('transaction.rollback');
            throw $e;

        } finally {
            $span->end();
        }
    }

    /**
     * Extract operation type from SQL statement
     */
    private function extractOperation(string $statement): string
    {
        $statement = trim($statement);
        $firstWord = strtoupper(strtok($statement, " \t\n\r"));

        return match ($firstWord) {
            'SELECT' => 'SELECT',
            'INSERT' => 'INSERT',
            'UPDATE' => 'UPDATE',
            'DELETE' => 'DELETE',
            'CREATE' => 'CREATE',
            'ALTER' => 'ALTER',
            'DROP' => 'DROP',
            'TRUNCATE' => 'TRUNCATE',
            'BEGIN', 'START' => 'BEGIN',
            'COMMIT' => 'COMMIT',
            'ROLLBACK' => 'ROLLBACK',
            default => 'QUERY',
        };
    }

    /**
     * Truncate statement if too long
     */
    private function truncateStatement(string $statement): string
    {
        if (strlen($statement) <= $this->config['max_statement_length']) {
            return $statement;
        }

        return substr($statement, 0, $this->config['max_statement_length']) . '...';
    }

    /**
     * Sanitize parameters (remove sensitive data)
     */
    private function sanitizeParameters(array $parameters): array
    {
        $sensitiveKeys = ['password', 'secret', 'token', 'key', 'auth'];
        $sanitized = [];

        foreach ($parameters as $key => $value) {
            $lowerKey = strtolower((string) $key);
            $isSensitive = false;

            foreach ($sensitiveKeys as $sensitive) {
                if (str_contains($lowerKey, $sensitive)) {
                    $isSensitive = true;
                    break;
                }
            }

            $sanitized[$key] = $isSensitive ? '[REDACTED]' : $value;
        }

        return $sanitized;
    }
}
