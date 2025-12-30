<?php

declare(strict_types=1);

namespace HybridPHP\Core\Tracing\Middleware;

use HybridPHP\Core\Tracing\SpanKind;
use HybridPHP\Core\Tracing\SpanStatus;
use HybridPHP\Core\Tracing\Tracer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * HTTP tracing middleware with automatic span injection
 * 
 * Automatically creates spans for incoming HTTP requests and
 * propagates trace context to responses
 */
class TracingMiddleware implements MiddlewareInterface
{
    private Tracer $tracer;
    private array $config;

    public function __construct(Tracer $tracer, array $config = [])
    {
        $this->tracer = $tracer;
        $this->config = array_merge([
            'record_headers' => false,
            'record_query_params' => true,
            'sensitive_headers' => ['authorization', 'cookie', 'x-api-key'],
            'excluded_paths' => ['/health', '/metrics', '/favicon.ico'],
        ], $config);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Check if path should be excluded
        $path = $request->getUri()->getPath();
        if ($this->isExcludedPath($path)) {
            return $handler->handle($request);
        }

        // Extract trace context from incoming request
        $headers = $this->extractHeaders($request);
        $parentContext = $this->tracer->extract($headers);

        // Start server span
        $operationName = sprintf('%s %s', $request->getMethod(), $this->normalizePath($path));
        $span = $this->tracer->startSpan($operationName, [], $parentContext);

        // Set span kind to SERVER
        if ($span instanceof \HybridPHP\Core\Tracing\Span) {
            // Add HTTP semantic conventions
            $span->setAttributes([
                'http.method' => $request->getMethod(),
                'http.url' => (string) $request->getUri(),
                'http.scheme' => $request->getUri()->getScheme(),
                'http.host' => $request->getUri()->getHost(),
                'http.target' => $request->getUri()->getPath(),
                'http.user_agent' => $request->getHeaderLine('User-Agent'),
                'http.request_content_length' => $request->getBody()->getSize(),
                'net.host.name' => $request->getUri()->getHost(),
                'net.host.port' => $request->getUri()->getPort() ?? ($request->getUri()->getScheme() === 'https' ? 443 : 80),
            ]);

            // Record query parameters if enabled
            if ($this->config['record_query_params']) {
                $queryParams = $request->getQueryParams();
                if (!empty($queryParams)) {
                    $span->setAttribute('http.query_params', json_encode($queryParams));
                }
            }

            // Record headers if enabled
            if ($this->config['record_headers']) {
                $filteredHeaders = $this->filterSensitiveHeaders($request->getHeaders());
                $span->setAttribute('http.request_headers', json_encode($filteredHeaders));
            }

            // Add client IP
            $clientIp = $this->getClientIp($request);
            if ($clientIp !== null) {
                $span->setAttribute('http.client_ip', $clientIp);
            }
        }

        // Store span in request attributes for downstream access
        $request = $request->withAttribute('tracing.span', $span);
        $request = $request->withAttribute('tracing.trace_id', $span->getTraceId());
        $request = $request->withAttribute('tracing.span_id', $span->getSpanId());

        try {
            // Process request
            $response = $handler->handle($request);

            // Record response attributes
            $span->setAttributes([
                'http.status_code' => $response->getStatusCode(),
                'http.response_content_length' => $response->getBody()->getSize(),
            ]);

            // Set status based on HTTP status code
            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                $span->setStatus(SpanStatus::ERROR, 'HTTP ' . $statusCode);
            } else {
                $span->setStatus(SpanStatus::OK);
            }

            // Inject trace context into response headers
            $responseHeaders = [];
            $this->tracer->inject($responseHeaders);
            foreach ($responseHeaders as $name => $value) {
                $response = $response->withHeader($name, $value);
            }

            // Add trace ID header for debugging
            $response = $response->withHeader('X-Trace-Id', $span->getTraceId());

            return $response;

        } catch (\Throwable $e) {
            // Record exception
            $span->recordException($e);
            $span->setStatus(SpanStatus::ERROR, $e->getMessage());

            throw $e;

        } finally {
            // End span
            $span->end();
        }
    }

    /**
     * Extract headers from request
     */
    private function extractHeaders(ServerRequestInterface $request): array
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = $values[0] ?? '';
        }
        return $headers;
    }

    /**
     * Check if path should be excluded from tracing
     */
    private function isExcludedPath(string $path): bool
    {
        foreach ($this->config['excluded_paths'] as $excluded) {
            if ($path === $excluded || str_starts_with($path, $excluded)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalize path for span name (replace IDs with placeholders)
     */
    private function normalizePath(string $path): string
    {
        // Replace UUIDs
        $path = preg_replace(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            '{uuid}',
            $path
        );

        // Replace numeric IDs
        $path = preg_replace('/\/\d+/', '/{id}', $path);

        return $path;
    }

    /**
     * Filter sensitive headers
     */
    private function filterSensitiveHeaders(array $headers): array
    {
        $filtered = [];
        foreach ($headers as $name => $values) {
            $lowerName = strtolower($name);
            if (in_array($lowerName, $this->config['sensitive_headers'])) {
                $filtered[$name] = ['[REDACTED]'];
            } else {
                $filtered[$name] = $values;
            }
        }
        return $filtered;
    }

    /**
     * Get client IP from request
     */
    private function getClientIp(ServerRequestInterface $request): ?string
    {
        // Check common proxy headers
        $headers = ['X-Forwarded-For', 'X-Real-IP', 'CF-Connecting-IP'];
        
        foreach ($headers as $header) {
            if ($request->hasHeader($header)) {
                $value = $request->getHeaderLine($header);
                // X-Forwarded-For may contain multiple IPs
                $ips = array_map('trim', explode(',', $value));
                return $ips[0];
            }
        }

        // Fall back to server params
        $serverParams = $request->getServerParams();
        return $serverParams['REMOTE_ADDR'] ?? null;
    }
}
