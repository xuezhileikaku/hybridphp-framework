<?php

namespace HybridPHP\Core\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * PSR-7 compatible HTTP Response implementation with Yii2-style convenience methods
 * Supports multi-format responses, content negotiation, and caching
 */
class Response implements ResponseInterface
{
    private int $statusCode;
    private string $reasonPhrase;
    private array $headers;
    private StreamInterface $body;
    private string $protocolVersion = '1.1';

    public function __construct(int $status = 200, array $headers = [], $body = '', string $version = '1.1', string $reason = '')
    {
        $this->statusCode = $status;
        $this->headers = $this->normalizeHeaders($headers);
        $this->body = $this->createStream($body);
        $this->protocolVersion = $version;
        $this->reasonPhrase = $reason ?: $this->getDefaultReasonPhrase($status);
    }

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

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        $new = clone $this;
        $new->statusCode = $code;
        $new->reasonPhrase = $reasonPhrase ?: $this->getDefaultReasonPhrase($code);
        return $new;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

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

            public function close(): void
            {
                // No-op for string stream
            }

            public function detach()
            {
                return null;
            }

            public function getSize(): ?int
            {
                return strlen($this->content);
            }

            public function tell(): int
            {
                return $this->position;
            }

            public function eof(): bool
            {
                return $this->position >= strlen($this->content);
            }

            public function isSeekable(): bool
            {
                return true;
            }

            public function seek(int $offset, int $whence = SEEK_SET): void
            {
                switch ($whence) {
                    case SEEK_SET:
                        $this->position = $offset;
                        break;
                    case SEEK_CUR:
                        $this->position += $offset;
                        break;
                    case SEEK_END:
                        $this->position = strlen($this->content) + $offset;
                        break;
                }
            }

            public function rewind(): void
            {
                $this->position = 0;
            }

            public function isWritable(): bool
            {
                return false;
            }

            public function write(string $string): int
            {
                throw new \RuntimeException('Stream is not writable');
            }

            public function isReadable(): bool
            {
                return true;
            }

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

            public function getMetadata(?string $key = null)
            {
                return $key === null ? [] : null;
            }
        };
    }

    private function getDefaultReasonPhrase(int $statusCode): string
    {
        $phrases = [
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            304 => 'Not Modified',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
        ];

        return $phrases[$statusCode] ?? '';
    }

    // Yii2-style convenience methods

    /**
     * Create JSON response
     */
    public static function json($data, int $status = 200, array $headers = [], int $options = 0): self
    {
        $json = json_encode($data, $options);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode JSON: ' . json_last_error_msg());
        }

        $headers['content-type'] = 'application/json; charset=utf-8';
        return new self($status, $headers, $json);
    }

    /**
     * Create XML response
     */
    public static function xml($data, int $status = 200, array $headers = [], string $rootElement = 'root'): self
    {
        if (is_array($data) || is_object($data)) {
            $xml = new \SimpleXMLElement("<{$rootElement}></{$rootElement}>");
            self::arrayToXml($data, $xml);
            $xmlString = $xml->asXML();
        } else {
            $xmlString = (string) $data;
        }

        $headers['content-type'] = 'application/xml; charset=utf-8';
        return new self($status, $headers, $xmlString);
    }

    /**
     * Create HTML response
     */
    public static function html(string $html, int $status = 200, array $headers = []): self
    {
        $headers['content-type'] = 'text/html; charset=utf-8';
        return new self($status, $headers, $html);
    }

    /**
     * Create plain text response
     */
    public static function text(string $text, int $status = 200, array $headers = []): self
    {
        $headers['content-type'] = 'text/plain; charset=utf-8';
        return new self($status, $headers, $text);
    }

    /**
     * Create redirect response
     */
    public static function redirect(string $url, int $status = 302, array $headers = []): self
    {
        $headers['location'] = $url;
        return new self($status, $headers);
    }

    /**
     * Create file download response
     */
    public static function download(string $filePath, string $filename = null, array $headers = []): self
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }

        $filename = $filename ?: basename($filePath);
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
        
        $headers['content-type'] = $mimeType;
        $headers['content-disposition'] = 'attachment; filename="' . $filename . '"';
        $headers['content-length'] = filesize($filePath);

        return new self(200, $headers, file_get_contents($filePath));
    }

    /**
     * Create response with caching headers
     */
    public function withCache(int $maxAge, bool $public = true): self
    {
        $cacheControl = $public ? 'public' : 'private';
        $cacheControl .= ", max-age={$maxAge}";
        
        return $this->withHeader('Cache-Control', $cacheControl)
                   ->withHeader('Expires', gmdate('D, d M Y H:i:s T', time() + $maxAge));
    }

    /**
     * Create response with no-cache headers
     */
    public function withNoCache(): self
    {
        return $this->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
                   ->withHeader('Pragma', 'no-cache')
                   ->withHeader('Expires', '0');
    }

    /**
     * Create response with ETag
     */
    public function withETag(string $etag, bool $weak = false): self
    {
        $etagValue = $weak ? 'W/"' . $etag . '"' : '"' . $etag . '"';
        return $this->withHeader('ETag', $etagValue);
    }

    /**
     * Create response with Last-Modified header
     */
    public function withLastModified(\DateTime $date): self
    {
        return $this->withHeader('Last-Modified', $date->format('D, d M Y H:i:s T'));
    }

    /**
     * Create CORS response
     */
    public function withCors(array $options = []): self
    {
        $defaults = [
            'origin' => '*',
            'methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            'headers' => 'Content-Type, Authorization, X-Requested-With',
            'credentials' => false,
            'max_age' => 86400
        ];

        $options = array_merge($defaults, $options);
        
        $response = $this->withHeader('Access-Control-Allow-Origin', $options['origin'])
                        ->withHeader('Access-Control-Allow-Methods', $options['methods'])
                        ->withHeader('Access-Control-Allow-Headers', $options['headers'])
                        ->withHeader('Access-Control-Max-Age', (string) $options['max_age']);

        if ($options['credentials']) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }

    /**
     * Create response based on content negotiation
     */
    public static function negotiate($data, Request $request, int $status = 200, array $headers = []): self
    {
        $acceptableTypes = $request->getAcceptableContentTypes();
        
        // Default to JSON if no specific preference
        if (empty($acceptableTypes)) {
            return self::json($data, $status, $headers);
        }

        foreach ($acceptableTypes as $type) {
            switch ($type) {
                case 'application/json':
                case 'text/json':
                    return self::json($data, $status, $headers);
                    
                case 'application/xml':
                case 'text/xml':
                    return self::xml($data, $status, $headers);
                    
                case 'text/html':
                    if (is_string($data)) {
                        return self::html($data, $status, $headers);
                    }
                    // Fall through to JSON for non-string data
                    break;
                    
                case 'text/plain':
                    return self::text((string) $data, $status, $headers);
            }
        }

        // Default to JSON
        return self::json($data, $status, $headers);
    }

    /**
     * Create API response with standard format
     */
    public static function api($data = null, string $message = '', int $status = 200, array $headers = []): self
    {
        $response = [
            'success' => $status >= 200 && $status < 300,
            'status' => $status,
            'message' => $message,
            'data' => $data
        ];

        return self::json($response, $status, $headers);
    }

    /**
     * Create success API response
     */
    public static function success($data = null, string $message = 'Success', int $status = 200): self
    {
        return self::api($data, $message, $status);
    }

    /**
     * Create error API response
     */
    public static function error(string $message = 'Error', int $status = 400, $errors = null): self
    {
        $data = $errors ? ['errors' => $errors] : null;
        return self::api($data, $message, $status);
    }

    /**
     * Create validation error response
     */
    public static function validationError(array $errors, string $message = 'Validation failed'): self
    {
        return self::error($message, 422, $errors);
    }

    /**
     * Create not found response
     */
    public static function notFound(string $message = 'Not Found'): self
    {
        return self::error($message, 404);
    }

    /**
     * Create unauthorized response
     */
    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return self::error($message, 401);
    }

    /**
     * Create forbidden response
     */
    public static function forbidden(string $message = 'Forbidden'): self
    {
        return self::error($message, 403);
    }

    /**
     * Create server error response
     */
    public static function serverError(string $message = 'Internal Server Error'): self
    {
        return self::error($message, 500);
    }

    /**
     * Check if response is successful (2xx)
     */
    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Check if response is a redirect (3xx)
     */
    public function isRedirect(): bool
    {
        return $this->statusCode >= 300 && $this->statusCode < 400;
    }

    /**
     * Check if response is a client error (4xx)
     */
    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    /**
     * Check if response is a server error (5xx)
     */
    public function isServerError(): bool
    {
        return $this->statusCode >= 500;
    }

    /**
     * Get response content as string
     */
    public function getContent(): string
    {
        return (string) $this->body;
    }

    /**
     * Convert array to XML
     */
    private static function arrayToXml($data, \SimpleXMLElement $xml): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if (is_numeric($key)) {
                    $key = 'item';
                }
                $subnode = $xml->addChild($key);
                self::arrayToXml($value, $subnode);
            } else {
                if (is_numeric($key)) {
                    $key = 'item';
                }
                $xml->addChild($key, htmlspecialchars((string) $value));
            }
        }
    }
}