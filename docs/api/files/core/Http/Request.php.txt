<?php

namespace HybridPHP\Core\Http;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * PSR-7 compatible HTTP Request implementation with Yii2-style convenience methods
 */
class Request implements ServerRequestInterface
{
    private string $method;
    private UriInterface $uri;
    private array $headers;
    private StreamInterface $body;
    private string $protocolVersion = '1.1';
    private array $serverParams;
    private array $cookieParams = [];
    private array $queryParams = [];
    private array $uploadedFiles = [];
    private ?array $parsedBody = null;
    private array $attributes = [];
    
    // Yii2-style convenience properties
    private ?RequestValidator $validator = null;
    private array $validationRules = [];
    private array $validationErrors = [];

    public function __construct(
        string $method = 'GET',
        UriInterface $uri = null,
        array $headers = [],
        $body = null,
        string $version = '1.1',
        array $serverParams = []
    ) {
        $this->method = strtoupper($method);
        $this->uri = $uri ?? new Uri();
        $this->headers = $this->normalizeHeaders($headers);
        $this->body = $this->createStream($body);
        $this->protocolVersion = $version;
        $this->serverParams = $serverParams;
        
        // Parse query parameters from URI
        if ($this->uri) {
            parse_str($this->uri->getQuery(), $this->queryParams);
        }
    }

    // PSR-7 ServerRequestInterface implementation
    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }

    public function withCookieParams(array $cookies): ServerRequestInterface
    {
        $new = clone $this;
        $new->cookieParams = $cookies;
        return $new;
    }

    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function withQueryParams(array $query): ServerRequestInterface
    {
        $new = clone $this;
        $new->queryParams = $query;
        return $new;
    }

    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
    {
        $new = clone $this;
        $new->uploadedFiles = $uploadedFiles;
        return $new;
    }

    public function getParsedBody()
    {
        return $this->parsedBody;
    }

    public function withParsedBody($data): ServerRequestInterface
    {
        $new = clone $this;
        $new->parsedBody = $data;
        return $new;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $name, $default = null)
    {
        return $this->attributes[$name] ?? $default;
    }

    public function withAttribute(string $name, $value): ServerRequestInterface
    {
        $new = clone $this;
        $new->attributes[$name] = $value;
        return $new;
    }

    public function withoutAttribute(string $name): ServerRequestInterface
    {
        $new = clone $this;
        unset($new->attributes[$name]);
        return $new;
    }

    // PSR-7 RequestInterface implementation
    public function getRequestTarget(): string
    {
        if ($this->uri) {
            $target = $this->uri->getPath();
            if ($this->uri->getQuery()) {
                $target .= '?' . $this->uri->getQuery();
            }
            return $target ?: '/';
        }
        return '/';
    }

    public function withRequestTarget(string $requestTarget): \Psr\Http\Message\RequestInterface
    {
        $new = clone $this;
        // For simplicity, we'll just store this in an attribute
        $new->attributes['request_target'] = $requestTarget;
        return $new;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod(string $method): \Psr\Http\Message\RequestInterface
    {
        $new = clone $this;
        $new->method = strtoupper($method);
        return $new;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): \Psr\Http\Message\RequestInterface
    {
        $new = clone $this;
        $new->uri = $uri;
        
        if (!$preserveHost && $uri->getHost()) {
            $new = $new->withHeader('Host', $uri->getHost());
        }
        
        return $new;
    }

    // PSR-7 MessageInterface implementation
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion(string $version): \Psr\Http\Message\MessageInterface
    {
        $new = clone $this;
        $new->protocolVersion = $version;
        return $new;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    public function getHeader(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): \Psr\Http\Message\MessageInterface
    {
        $new = clone $this;
        $new->headers[strtolower($name)] = is_array($value) ? $value : [$value];
        return $new;
    }

    public function withAddedHeader(string $name, $value): \Psr\Http\Message\MessageInterface
    {
        $new = clone $this;
        $key = strtolower($name);
        $new->headers[$key] = array_merge($this->headers[$key] ?? [], is_array($value) ? $value : [$value]);
        return $new;
    }

    public function withoutHeader(string $name): \Psr\Http\Message\MessageInterface
    {
        $new = clone $this;
        unset($new->headers[strtolower($name)]);
        return $new;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): \Psr\Http\Message\MessageInterface
    {
        $new = clone $this;
        $new->body = $body;
        return $new;
    }

    // Yii2-style convenience methods
    
    /**
     * Get request parameter (query or post)
     */
    public function get(string $name, $default = null)
    {
        // Check query params first
        if (isset($this->queryParams[$name])) {
            return $this->queryParams[$name];
        }
        
        // Check parsed body
        if (is_array($this->parsedBody) && isset($this->parsedBody[$name])) {
            return $this->parsedBody[$name];
        }
        
        return $default;
    }

    /**
     * Get query parameter
     */
    public function getQuery(string $name = null, $default = null)
    {
        if ($name === null) {
            return $this->queryParams;
        }
        return $this->queryParams[$name] ?? $default;
    }

    /**
     * Get POST parameter
     */
    public function post(string $name = null, $default = null)
    {
        if ($name === null) {
            return $this->parsedBody;
        }
        
        if (is_array($this->parsedBody)) {
            return $this->parsedBody[$name] ?? $default;
        }
        
        return $default;
    }

    /**
     * Get cookie value
     */
    public function getCookie(string $name, $default = null)
    {
        return $this->cookieParams[$name] ?? $default;
    }

    /**
     * Get server parameter
     */
    public function getServer(string $name = null, $default = null)
    {
        if ($name === null) {
            return $this->serverParams;
        }
        return $this->serverParams[$name] ?? $default;
    }

    /**
     * Check if request is AJAX
     */
    public function isAjax(): bool
    {
        return strtolower($this->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';
    }

    /**
     * Check if request is JSON
     */
    public function isJson(): bool
    {
        $contentType = $this->getHeaderLine('Content-Type');
        return strpos($contentType, 'application/json') !== false;
    }

    /**
     * Check if request method is GET
     */
    public function isGet(): bool
    {
        return $this->method === 'GET';
    }

    /**
     * Check if request method is POST
     */
    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /**
     * Check if request method is PUT
     */
    public function isPut(): bool
    {
        return $this->method === 'PUT';
    }

    /**
     * Check if request method is DELETE
     */
    public function isDelete(): bool
    {
        return $this->method === 'DELETE';
    }

    /**
     * Check if request method is PATCH
     */
    public function isPatch(): bool
    {
        return $this->method === 'PATCH';
    }

    /**
     * Get client IP address
     */
    public function getClientIp(): string
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
            if (!empty($this->serverParams[$header])) {
                $ip = $this->serverParams[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $this->serverParams['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Get user agent
     */
    public function getUserAgent(): string
    {
        return $this->getHeaderLine('User-Agent');
    }

    /**
     * Get referrer URL
     */
    public function getReferrer(): string
    {
        return $this->getHeaderLine('Referer');
    }

    /**
     * Get accepted content types
     */
    public function getAcceptableContentTypes(): array
    {
        $accept = $this->getHeaderLine('Accept');
        if (empty($accept)) {
            return [];
        }

        $types = [];
        foreach (explode(',', $accept) as $type) {
            $type = trim($type);
            if (strpos($type, ';') !== false) {
                $type = trim(explode(';', $type)[0]);
            }
            $types[] = $type;
        }

        return $types;
    }

    /**
     * Check if content type is acceptable
     */
    public function accepts(string $contentType): bool
    {
        $acceptable = $this->getAcceptableContentTypes();
        return in_array($contentType, $acceptable) || in_array('*/*', $acceptable);
    }

    /**
     * Get preferred content type
     */
    public function getPreferredContentType(array $available = []): string
    {
        $acceptable = $this->getAcceptableContentTypes();
        
        if (empty($available)) {
            return $acceptable[0] ?? 'text/html';
        }

        foreach ($acceptable as $type) {
            if (in_array($type, $available)) {
                return $type;
            }
        }

        return $available[0] ?? 'text/html';
    }

    // Validation methods (Yii2-style)
    
    /**
     * Set validation rules
     */
    public function rules(array $rules): self
    {
        $this->validationRules = $rules;
        return $this;
    }

    /**
     * Validate request data
     */
    public function validate(array $rules = null): bool
    {
        if ($rules !== null) {
            $this->validationRules = $rules;
        }

        if (empty($this->validationRules)) {
            return true;
        }

        $this->validator = new RequestValidator($this);
        return $this->validator->validate($this->validationRules);
    }

    /**
     * Get validation errors
     */
    public function getErrors(): array
    {
        return $this->validator ? $this->validator->getErrors() : [];
    }

    /**
     * Check if validation has errors
     */
    public function hasErrors(): bool
    {
        return !empty($this->getErrors());
    }

    /**
     * Get first validation error
     */
    public function getFirstError(): string
    {
        $errors = $this->getErrors();
        if (empty($errors)) {
            return '';
        }
        
        $firstField = array_keys($errors)[0];
        return $errors[$firstField][0] ?? '';
    }

    // Helper methods
    
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = is_array($value) ? $value : [$value];
        }
        return $normalized;
    }

    private function createStream($body): StreamInterface
    {
        if ($body instanceof StreamInterface) {
            return $body;
        }

        return new class((string) $body) implements StreamInterface {
            private string $content;
            private int $position = 0;

            public function __construct(string $content)
            {
                $this->content = $content;
            }

            public function __toString(): string
            {
                return $this->content;
            }

            public function close(): void {}
            public function detach() { return null; }
            public function getSize(): ?int { return strlen($this->content); }
            public function tell(): int { return $this->position; }
            public function eof(): bool { return $this->position >= strlen($this->content); }
            public function isSeekable(): bool { return true; }
            public function seek(int $offset, int $whence = SEEK_SET): void
            {
                switch ($whence) {
                    case SEEK_SET: $this->position = $offset; break;
                    case SEEK_CUR: $this->position += $offset; break;
                    case SEEK_END: $this->position = strlen($this->content) + $offset; break;
                }
            }
            public function rewind(): void { $this->position = 0; }
            public function isWritable(): bool { return false; }
            public function write(string $string): int { throw new \RuntimeException('Stream is not writable'); }
            public function isReadable(): bool { return true; }
            public function read(int $length): string
            {
                $data = substr($this->content, $this->position, $length);
                $this->position += strlen($data);
                return $data;
            }
            public function getContents(): string
            {
                $contents = substr($this->content, $this->position);
                $this->position = strlen($this->content);
                return $contents;
            }
            public function getMetadata(?string $key = null) { return $key === null ? [] : null; }
        };
    }
}