<?php
namespace HybridPHP\Core\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Async XSS Protection Middleware
 * Protects against Cross-Site Scripting attacks
 */
class XssProtectionMiddleware extends AbstractMiddleware
{
    private array $config;
    private array $dangerousPatterns;
    private array $allowedTags;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'sanitize_input' => true,
            'sanitize_output' => false,
            'strict_mode' => false,
            'allowed_tags' => ['p', 'br', 'strong', 'em', 'u', 'i'],
            'blocked_attributes' => ['onclick', 'onload', 'onerror', 'onmouseover', 'onfocus', 'onblur'],
            'content_types' => ['text/html', 'application/json', 'text/plain'],
        ], $config);

        $this->allowedTags = $this->config['allowed_tags'];
        $this->initializeDangerousPatterns();
    }

    /**
     * Initialize patterns for detecting XSS attempts
     */
    private function initializeDangerousPatterns(): void
    {
        $this->dangerousPatterns = [
            // Script tags
            '/<script[^>]*>.*?<\/script>/is',
            '/<script[^>]*>/i',
            
            // JavaScript protocols
            '/javascript:/i',
            '/vbscript:/i',
            '/data:/i',
            
            // Event handlers
            '/on\w+\s*=/i',
            
            // Meta refresh
            '/<meta[^>]*http-equiv[^>]*refresh/i',
            
            // Object/embed/applet
            '/<(object|embed|applet|iframe)[^>]*>/i',
            
            // Style with expression
            '/style\s*=.*expression\s*\(/i',
            
            // Base64 encoded scripts
            '/data:text\/html;base64,/i',
        ];
    }

    protected function before(ServerRequestInterface $request): ServerRequestInterface
    {
        if (!$this->config['sanitize_input']) {
            return $request;
        }

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

        // Sanitize headers (be careful not to break functionality)
        $sanitizedHeaders = [];
        foreach ($request->getHeaders() as $name => $values) {
            if ($this->shouldSanitizeHeader($name)) {
                $sanitizedHeaders[$name] = array_map([$this, 'sanitizeString'], $values);
            } else {
                $sanitizedHeaders[$name] = $values;
            }
        }

        foreach ($sanitizedHeaders as $name => $values) {
            $request = $request->withHeader($name, $values);
        }

        return $request;
    }

    protected function after(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->config['sanitize_output']) {
            return $response;
        }

        $contentType = $response->getHeaderLine('Content-Type');
        
        // Only sanitize specific content types
        if (!$this->shouldSanitizeContentType($contentType)) {
            return $response;
        }

        $body = $response->getBody();
        $content = (string) $body;

        if (!empty($content)) {
            $sanitizedContent = $this->sanitizeString($content);
            
            // Create new stream with sanitized content
            $newBody = \Amp\ByteStream\buffer($sanitizedContent);
            $response = $response->withBody($newBody);
        }

        // Add XSS protection headers
        $response = $this->addXssHeaders($response);

        return $response;
    }

    /**
     * Sanitize an array recursively
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
     * Sanitize a string to prevent XSS
     *
     * @param string $input
     * @return string
     */
    private function sanitizeString(string $input): string
    {
        if (empty($input)) {
            return $input;
        }

        // First, check for dangerous patterns
        if ($this->containsDangerousPattern($input)) {
            if ($this->config['strict_mode']) {
                return ''; // Remove completely in strict mode
            }
        }

        // HTML encode special characters
        $sanitized = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Remove dangerous patterns
        foreach ($this->dangerousPatterns as $pattern) {
            $sanitized = preg_replace($pattern, '', $sanitized);
        }

        // Strip tags, keeping only allowed ones
        if (!empty($this->allowedTags)) {
            $allowedTagsString = '<' . implode('><', $this->allowedTags) . '>';
            $sanitized = strip_tags($sanitized, $allowedTagsString);
        } else {
            $sanitized = strip_tags($sanitized);
        }

        // Remove blocked attributes
        foreach ($this->config['blocked_attributes'] as $attribute) {
            $sanitized = preg_replace('/' . $attribute . '\s*=\s*["\'][^"\']*["\']/i', '', $sanitized);
        }

        return $sanitized;
    }

    /**
     * Check if input contains dangerous patterns
     *
     * @param string $input
     * @return bool
     */
    private function containsDangerousPattern(string $input): bool
    {
        foreach ($this->dangerousPatterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if header should be sanitized
     *
     * @param string $headerName
     * @return bool
     */
    private function shouldSanitizeHeader(string $headerName): bool
    {
        $sanitizeHeaders = [
            'user-agent',
            'referer',
            'x-forwarded-for',
            'x-real-ip',
        ];

        return in_array(strtolower($headerName), $sanitizeHeaders);
    }

    /**
     * Check if content type should be sanitized
     *
     * @param string $contentType
     * @return bool
     */
    private function shouldSanitizeContentType(string $contentType): bool
    {
        foreach ($this->config['content_types'] as $type) {
            if (str_contains($contentType, $type)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Add XSS protection headers
     *
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    private function addXssHeaders(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('X-XSS-Protection', '1; mode=block')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY');
    }
}