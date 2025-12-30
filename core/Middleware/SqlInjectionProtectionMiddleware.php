<?php
namespace HybridPHP\Core\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use HybridPHP\Core\Http\Response;

/**
 * Async SQL Injection Protection Middleware
 * Detects and prevents SQL injection attempts
 */
class SqlInjectionProtectionMiddleware extends AbstractMiddleware
{
    private array $config;
    private array $sqlPatterns;
    private array $suspiciousPatterns;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'strict_mode' => false,
            'log_attempts' => true,
            'block_suspicious' => true,
            'sanitize_input' => true,
            'max_query_length' => 1000,
            'allowed_sql_keywords' => [], // For specific use cases where SQL keywords are legitimate
        ], $config);

        $this->initializeSqlPatterns();
    }

    /**
     * Initialize SQL injection detection patterns
     */
    private function initializeSqlPatterns(): void
    {
        // Common SQL injection patterns
        $this->sqlPatterns = [
            // Union-based injections
            '/\bunion\b.*\bselect\b/i',
            '/\bunion\b.*\ball\b.*\bselect\b/i',
            
            // Boolean-based blind injections
            '/\b(and|or)\b\s+\d+\s*=\s*\d+/i',
            '/\b(and|or)\b\s+[\'"]?\w+[\'"]?\s*=\s*[\'"]?\w+[\'"]?/i',
            
            // Time-based blind injections
            '/\bsleep\s*\(/i',
            '/\bwaitfor\s+delay\b/i',
            '/\bbenchmark\s*\(/i',
            
            // Error-based injections
            '/\bextractvalue\s*\(/i',
            '/\bupdatexml\s*\(/i',
            
            // Stacked queries
            '/;\s*(drop|delete|insert|update|create|alter)\b/i',
            
            // Comment-based injections
            '/\/\*.*\*\//s',
            '/--\s*.*$/m',
            '/#.*$/m',
            
            // Information schema queries
            '/\binformation_schema\b/i',
            '/\bsys\.\b/i',
            '/\bmysql\.\b/i',
            
            // Function-based injections
            '/\b(concat|group_concat|load_file|into\s+outfile)\b/i',
            
            // Hex encoding attempts
            '/0x[0-9a-f]+/i',
            
            // SQL keywords in suspicious contexts
            '/[\'"]?\s*(select|insert|update|delete|drop|create|alter|exec|execute)\s+/i',
        ];

        // Suspicious patterns that might indicate injection attempts
        $this->suspiciousPatterns = [
            // Multiple quotes
            '/[\'\"]{2,}/',
            
            // SQL operators in unusual contexts
            '/\s+(and|or)\s+\d+/i',
            
            // Parentheses with SQL keywords
            '/\(\s*(select|union|insert)\b/i',
            
            // Semicolon followed by SQL keywords
            '/;\s*(select|insert|update|delete|drop)/i',
        ];
    }

    protected function before(ServerRequestInterface $request): ServerRequestInterface
    {
        // Check query parameters
        $queryParams = $request->getQueryParams();
        if (!empty($queryParams) && $this->containsSqlInjection($queryParams)) {
            throw new \RuntimeException('SQL injection attempt detected in query parameters');
        }

        // Check POST data
        $parsedBody = $request->getParsedBody();
        if (is_array($parsedBody) && $this->containsSqlInjection($parsedBody)) {
            throw new \RuntimeException('SQL injection attempt detected in request body');
        }

        // Check headers for injection attempts
        foreach ($request->getHeaders() as $name => $values) {
            if ($this->shouldCheckHeader($name)) {
                foreach ($values as $value) {
                    if ($this->isSqlInjection($value)) {
                        throw new \RuntimeException('SQL injection attempt detected in headers');
                    }
                }
            }
        }

        // Sanitize input if configured
        if ($this->config['sanitize_input']) {
            $request = $this->sanitizeRequest($request);
        }

        return $request;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return parent::process($request, $handler);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'SQL injection')) {
                if ($this->config['log_attempts']) {
                    $this->logSqlInjectionAttempt($request, $e->getMessage());
                }
                
                return $this->forbiddenResponse('Invalid request detected');
            }
            
            throw $e;
        }
    }

    /**
     * Check if data contains SQL injection patterns
     *
     * @param array $data
     * @return bool
     */
    private function containsSqlInjection(array $data): bool
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if ($this->containsSqlInjection($value)) {
                    return true;
                }
            } else {
                if ($this->isSqlInjection((string) $value) || $this->isSqlInjection((string) $key)) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Check if a string contains SQL injection patterns
     *
     * @param string $input
     * @return bool
     */
    private function isSqlInjection(string $input): bool
    {
        if (empty($input)) {
            return false;
        }

        // Check length limit
        if (strlen($input) > $this->config['max_query_length']) {
            return true;
        }

        // URL decode the input to catch encoded injections
        $decoded = urldecode($input);
        
        // Check against SQL injection patterns
        foreach ($this->sqlPatterns as $pattern) {
            if (preg_match($pattern, $decoded)) {
                return true;
            }
        }

        // Check suspicious patterns if configured
        if ($this->config['block_suspicious']) {
            foreach ($this->suspiciousPatterns as $pattern) {
                if (preg_match($pattern, $decoded)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Sanitize request to remove potential SQL injection
     *
     * @param ServerRequestInterface $request
     * @return ServerRequestInterface
     */
    private function sanitizeRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        // Sanitize query parameters
        $queryParams = $request->getQueryParams();
        if (!empty($queryParams)) {
            $sanitizedQuery = $this->sanitizeArray($queryParams);
            $request = $request->withQueryParams($sanitizedQuery);
        }

        // Sanitize POST data
        $parsedBody = $request->getParsedBody();
        if (is_array($parsedBody)) {
            $sanitizedBody = $this->sanitizeArray($parsedBody);
            $request = $request->withParsedBody($sanitizedBody);
        }

        return $request;
    }

    /**
     * Sanitize array recursively
     *
     * @param array $data
     * @return array
     */
    private function sanitizeArray(array $data): array
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            $sanitizedKey = $this->sanitizeString((string) $key);
            
            if (is_array($value)) {
                $sanitized[$sanitizedKey] = $this->sanitizeArray($value);
            } else {
                $sanitized[$sanitizedKey] = $this->sanitizeString((string) $value);
            }
        }
        
        return $sanitized;
    }

    /**
     * Sanitize string to remove SQL injection patterns
     *
     * @param string $input
     * @return string
     */
    private function sanitizeString(string $input): string
    {
        if (empty($input)) {
            return $input;
        }

        // Remove SQL injection patterns
        foreach ($this->sqlPatterns as $pattern) {
            $input = preg_replace($pattern, '', $input);
        }

        // Escape SQL special characters
        $input = str_replace([
            "'", '"', '\\', '/', '*', '+', '?', '[', '^', ']', '$', '(', ')', '{', '}', '=', '!', '<', '>', '|', ':', '-'
        ], '', $input);

        // Remove excessive whitespace
        $input = preg_replace('/\s+/', ' ', $input);
        
        return trim($input);
    }

    /**
     * Check if header should be checked for SQL injection
     *
     * @param string $headerName
     * @return bool
     */
    private function shouldCheckHeader(string $headerName): bool
    {
        $checkHeaders = [
            'user-agent',
            'referer',
            'x-forwarded-for',
            'x-real-ip',
            'cookie',
        ];

        return in_array(strtolower($headerName), $checkHeaders);
    }

    /**
     * Log SQL injection attempt
     *
     * @param ServerRequestInterface $request
     * @param string $message
     */
    private function logSqlInjectionAttempt(ServerRequestInterface $request, string $message): void
    {
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'ip' => $request->getHeaderLine('X-Forwarded-For') ?: $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown',
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'user_agent' => $request->getHeaderLine('User-Agent'),
            'message' => $message,
            'query_params' => $request->getQueryParams(),
            'post_data' => $request->getParsedBody(),
        ];

        // In a real implementation, you'd use your logging system
        error_log('SQL Injection Attempt: ' . json_encode($logData));
    }

    /**
     * Return forbidden response
     *
     * @param string $message
     * @return ResponseInterface
     */
    private function forbiddenResponse(string $message = 'Forbidden'): ResponseInterface
    {
        return new Response(403, [
            'Content-Type' => 'application/json'
        ], json_encode([
            'error' => $message,
            'code' => 403
        ]));
    }
}