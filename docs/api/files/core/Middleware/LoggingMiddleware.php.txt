<?php
namespace HybridPHP\Core\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;


/**
 * Async logging middleware
 * Logs HTTP requests and responses asynchronously
 */
class LoggingMiddleware extends AbstractMiddleware
{
    private LoggerInterface $logger;
    private array $config;

    public function __construct(LoggerInterface $logger, array $config = [])
    {
        $this->logger = $logger;
        $this->config = array_merge([
            'log_requests' => true,
            'log_responses' => true,
            'log_request_body' => false,
            'log_response_body' => false,
            'excluded_paths' => ['/health', '/metrics'],
            'sensitive_headers' => ['Authorization', 'Cookie', 'X-API-Key'],
            'max_body_size' => 1024, // Max body size to log in bytes
        ], $config);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $startTime = microtime(true);
        $requestId = $this->generateRequestId();
        
        // Add request ID to request attributes
        $request = $request->withAttribute('request_id', $requestId);
        
        // Log request if enabled and not excluded
        if ($this->config['log_requests'] && !$this->isPathExcluded($request->getUri()->getPath())) {
            $this->logRequest($request, $requestId);
        }

        try {
            // Process the request
            $response = $handler->handle($request);
            
            // Log response if enabled
            if ($this->config['log_responses'] && !$this->isPathExcluded($request->getUri()->getPath())) {
                $duration = microtime(true) - $startTime;
                $this->logResponse($request, $response, $requestId, $duration);
            }
            
            return $response;
        } catch (\Throwable $e) {
            // Log error
            $duration = microtime(true) - $startTime;
            $this->logError($request, $e, $requestId, $duration);
            throw $e;
        }
    }

    /**
     * Log HTTP request
     *
     * @param ServerRequestInterface $request
     * @param string $requestId
     * @return void
     */
    private function logRequest(ServerRequestInterface $request, string $requestId): void
    {
        $logData = [
            'request_id' => $requestId,
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'headers' => $this->sanitizeHeaders($request->getHeaders()),
            'query_params' => $request->getQueryParams(),
            'remote_addr' => $this->getClientIp($request),
            'user_agent' => $request->getHeaderLine('User-Agent'),
        ];

        if ($this->config['log_request_body']) {
            $body = (string) $request->getBody();
            if (strlen($body) <= $this->config['max_body_size']) {
                $logData['body'] = $body;
            } else {
                $logData['body'] = '[Body too large to log]';
            }
        }

        $this->logger->info('HTTP Request', $logData);
    }

    /**
     * Log HTTP response
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param string $requestId
     * @param float $duration
     * @return void
     */
    private function logResponse(ServerRequestInterface $request, ResponseInterface $response, string $requestId, float $duration): void
    {
        $logData = [
            'request_id' => $requestId,
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'status_code' => $response->getStatusCode(),
            'headers' => $this->sanitizeHeaders($response->getHeaders()),
            'duration_ms' => round($duration * 1000, 2),
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
        ];

        if ($this->config['log_response_body']) {
            $body = (string) $response->getBody();
            if (strlen($body) <= $this->config['max_body_size']) {
                $logData['body'] = $body;
            } else {
                $logData['body'] = '[Body too large to log]';
            }
        }

        $level = $response->getStatusCode() >= 400 ? 'warning' : 'info';
        $this->logger->log($level, 'HTTP Response', $logData);
    }

    /**
     * Log HTTP error
     *
     * @param ServerRequestInterface $request
     * @param \Throwable $error
     * @param string $requestId
     * @param float $duration
     * @return void
     */
    private function logError(ServerRequestInterface $request, \Throwable $error, string $requestId, float $duration): void
    {
        $logData = [
            'request_id' => $requestId,
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'error_message' => $error->getMessage(),
            'error_code' => $error->getCode(),
            'error_file' => $error->getFile(),
            'error_line' => $error->getLine(),
            'duration_ms' => round($duration * 1000, 2),
            'trace' => $error->getTraceAsString(),
        ];

        $this->logger->error('HTTP Error', $logData);
    }

    /**
     * Sanitize headers by removing sensitive information
     *
     * @param array $headers
     * @return array
     */
    private function sanitizeHeaders(array $headers): array
    {
        $sanitized = [];
        
        foreach ($headers as $name => $values) {
            if (in_array($name, $this->config['sensitive_headers'])) {
                $sanitized[$name] = ['[REDACTED]'];
            } else {
                $sanitized[$name] = $values;
            }
        }
        
        return $sanitized;
    }

    /**
     * Get client IP address
     *
     * @param ServerRequestInterface $request
     * @return string
     */
    private function getClientIp(ServerRequestInterface $request): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            $ip = $request->getHeaderLine($header);
            if (!empty($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }

        return $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Generate unique request ID
     *
     * @return string
     */
    private function generateRequestId(): string
    {
        return uniqid('req_', true);
    }

    /**
     * Check if path should be excluded from logging
     *
     * @param string $path
     * @return bool
     */
    private function isPathExcluded(string $path): bool
    {
        foreach ($this->config['excluded_paths'] as $excludedPath) {
            if (str_starts_with($path, $excludedPath)) {
                return true;
            }
        }
        
        return false;
    }
}