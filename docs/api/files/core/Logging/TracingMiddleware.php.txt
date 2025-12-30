<?php
namespace HybridPHP\Core\Logging;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tracing Middleware for automatic request tracing and logging
 */
class TracingMiddleware implements MiddlewareInterface
{
    private LoggerInterface $logger;
    private array $config;

    public function __construct(LoggerInterface $logger, array $config = [])
    {
        $this->logger = $logger;
        $this->config = array_merge([
            'log_requests' => true,
            'log_responses' => true,
            'log_headers' => false,
            'log_body' => false,
            'sensitive_headers' => ['authorization', 'cookie', 'x-api-key'],
            'max_body_size' => 1024,
        ], $config);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Extract or create trace context
        $this->extractTraceContext($request);
        
        // Start request span
        $spanId = DistributedTracing::startSpan('http_request', [
            'http.method' => $request->getMethod(),
            'http.url' => (string) $request->getUri(),
            'http.scheme' => $request->getUri()->getScheme(),
            'http.host' => $request->getUri()->getHost(),
            'http.target' => $request->getUri()->getPath(),
            'user_agent' => $request->getHeaderLine('User-Agent'),
        ]);

        $startTime = microtime(true);
        
        // Log request if enabled
        if ($this->config['log_requests']) {
            $this->logRequest($request);
        }

        try {
            // Process request
            $response = $handler->handle($request);
            
            $duration = microtime(true) - $startTime;
            
            // Add response tags to span
            DistributedTracing::setTag('http.status_code', $response->getStatusCode());
            DistributedTracing::setTag('http.response_size', $response->getBody()->getSize());
            DistributedTracing::setTag('duration', $duration);
            
            // Log response if enabled
            if ($this->config['log_responses']) {
                $this->logResponse($response, $duration);
            }
            
            // Finish span with success
            DistributedTracing::finishSpan([
                'success' => true,
                'status_code' => $response->getStatusCode(),
            ]);
            
            // Add tracing headers to response
            $response = $this->addTracingHeaders($response);
            
            return $response;
            
        } catch (\Throwable $e) {
            $duration = microtime(true) - $startTime;
            
            // Add error tags to span
            DistributedTracing::setTag('error', true);
            DistributedTracing::setTag('error.kind', get_class($e));
            DistributedTracing::setTag('error.message', $e->getMessage());
            
            // Log error to span
            DistributedTracing::logToSpan('Exception occurred', [
                'exception.class' => get_class($e),
                'exception.message' => $e->getMessage(),
                'exception.file' => $e->getFile(),
                'exception.line' => $e->getLine(),
            ]);
            
            // Log error
            $this->logger->error('Request processing failed', [
                'exception' => $e,
                'request_method' => $request->getMethod(),
                'request_uri' => (string) $request->getUri(),
                'duration' => $duration,
            ]);
            
            // Finish span with error
            DistributedTracing::finishSpan([
                'success' => false,
                'error' => true,
            ]);
            
            throw $e;
        }
    }

    /**
     * Extract trace context from request headers
     */
    private function extractTraceContext(ServerRequestInterface $request): void
    {
        $headers = [];
        
        // Check for various tracing header formats
        if ($request->hasHeader('x-trace-id')) {
            $headers['x-trace-id'] = $request->getHeaderLine('x-trace-id');
            $headers['x-span-id'] = $request->getHeaderLine('x-span-id');
            $headers['x-parent-span-id'] = $request->getHeaderLine('x-parent-span-id');
        } elseif ($request->hasHeader('traceparent')) {
            $headers['traceparent'] = $request->getHeaderLine('traceparent');
        } elseif ($request->hasHeader('uber-trace-id')) {
            $headers['uber-trace-id'] = $request->getHeaderLine('uber-trace-id');
        } elseif ($request->hasHeader('x-b3-traceid')) {
            $headers['x-b3-traceid'] = $request->getHeaderLine('x-b3-traceid');
            $headers['x-b3-spanid'] = $request->getHeaderLine('x-b3-spanid');
            $headers['x-b3-parentspanid'] = $request->getHeaderLine('x-b3-parentspanid');
        }
        
        if (!empty($headers)) {
            DistributedTracing::extractFromHeaders($headers);
        } else {
            // Start new trace
            DistributedTracing::startTrace('http_request');
        }
    }

    /**
     * Log incoming request
     */
    private function logRequest(ServerRequestInterface $request): void
    {
        $logData = [
            'type' => 'request',
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'protocol' => $request->getProtocolVersion(),
            'query_params' => $request->getQueryParams(),
        ];

        // Add headers if enabled
        if ($this->config['log_headers']) {
            $logData['headers'] = $this->filterSensitiveHeaders($request->getHeaders());
        }

        // Add body if enabled and not too large
        if ($this->config['log_body'] && $request->getBody()->getSize() <= $this->config['max_body_size']) {
            $body = (string) $request->getBody();
            if (!empty($body)) {
                $logData['body'] = $this->sanitizeBody($body);
            }
        }

        $this->logger->info('Incoming HTTP request', $logData);
    }

    /**
     * Log outgoing response
     */
    private function logResponse(ResponseInterface $response, float $duration): void
    {
        $logData = [
            'type' => 'response',
            'status_code' => $response->getStatusCode(),
            'reason_phrase' => $response->getReasonPhrase(),
            'duration' => round($duration * 1000, 2), // Convert to milliseconds
            'response_size' => $response->getBody()->getSize(),
        ];

        // Add headers if enabled
        if ($this->config['log_headers']) {
            $logData['headers'] = $response->getHeaders();
        }

        // Add body if enabled and not too large
        if ($this->config['log_body'] && $response->getBody()->getSize() <= $this->config['max_body_size']) {
            $body = (string) $response->getBody();
            if (!empty($body)) {
                $logData['body'] = $this->sanitizeBody($body);
            }
        }

        $level = $response->getStatusCode() >= 400 ? 'warning' : 'info';
        $this->logger->log($level, 'HTTP response sent', $logData);
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
                $filtered[$name] = ['[FILTERED]'];
            } else {
                $filtered[$name] = $values;
            }
        }
        
        return $filtered;
    }

    /**
     * Sanitize request/response body
     */
    private function sanitizeBody(string $body): string
    {
        // Try to decode JSON and filter sensitive fields
        $decoded = json_decode($body, true);
        
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return json_encode($this->filterSensitiveData($decoded));
        }
        
        return $body;
    }

    /**
     * Filter sensitive data from arrays
     */
    private function filterSensitiveData(array $data): array
    {
        $sensitiveFields = ['password', 'token', 'secret', 'key', 'authorization'];
        
        foreach ($data as $key => $value) {
            $lowerKey = strtolower($key);
            
            if (in_array($lowerKey, $sensitiveFields)) {
                $data[$key] = '[FILTERED]';
            } elseif (is_array($value)) {
                $data[$key] = $this->filterSensitiveData($value);
            }
        }
        
        return $data;
    }

    /**
     * Add tracing headers to response
     */
    private function addTracingHeaders(ResponseInterface $response): ResponseInterface
    {
        $headers = DistributedTracing::injectIntoHeaders();
        
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        
        return $response;
    }
}