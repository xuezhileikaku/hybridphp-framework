<?php

namespace HybridPHP\Core\Http;

/**
 * Response factory with Yii2-style static methods for creating responses
 */
class ResponseFactory
{
    /**
     * Create JSON response
     */
    public static function json($data, int $status = 200, array $headers = [], int $options = 0): Response
    {
        return Response::json($data, $status, $headers, $options);
    }

    /**
     * Create XML response
     */
    public static function xml($data, int $status = 200, array $headers = [], string $rootElement = 'root'): Response
    {
        return Response::xml($data, $status, $headers, $rootElement);
    }

    /**
     * Create HTML response
     */
    public static function html(string $html, int $status = 200, array $headers = []): Response
    {
        return Response::html($html, $status, $headers);
    }

    /**
     * Create plain text response
     */
    public static function text(string $text, int $status = 200, array $headers = []): Response
    {
        return Response::text($text, $status, $headers);
    }

    /**
     * Create redirect response
     */
    public static function redirect(string $url, int $status = 302, array $headers = []): Response
    {
        return Response::redirect($url, $status, $headers);
    }

    /**
     * Create file download response
     */
    public static function download(string $filePath, string $filename = null, array $headers = []): Response
    {
        return Response::download($filePath, $filename, $headers);
    }

    /**
     * Create response based on content negotiation
     */
    public static function negotiate($data, Request $request, int $status = 200, array $headers = []): Response
    {
        return Response::negotiate($data, $request, $status, $headers);
    }

    /**
     * Create API response with standard format
     */
    public static function api($data = null, string $message = '', int $status = 200, array $headers = []): Response
    {
        return Response::api($data, $message, $status, $headers);
    }

    /**
     * Create success API response
     */
    public static function success($data = null, string $message = 'Success', int $status = 200): Response
    {
        return Response::success($data, $message, $status);
    }

    /**
     * Create error API response
     */
    public static function error(string $message = 'Error', int $status = 400, $errors = null): Response
    {
        return Response::error($message, $status, $errors);
    }

    /**
     * Create validation error response
     */
    public static function validationError(array $errors, string $message = 'Validation failed'): Response
    {
        return Response::validationError($errors, $message);
    }

    /**
     * Create not found response
     */
    public static function notFound(string $message = 'Not Found'): Response
    {
        return Response::notFound($message);
    }

    /**
     * Create unauthorized response
     */
    public static function unauthorized(string $message = 'Unauthorized'): Response
    {
        return Response::unauthorized($message);
    }

    /**
     * Create forbidden response
     */
    public static function forbidden(string $message = 'Forbidden'): Response
    {
        return Response::forbidden($message);
    }

    /**
     * Create server error response
     */
    public static function serverError(string $message = 'Internal Server Error'): Response
    {
        return Response::serverError($message);
    }

    /**
     * Create response with caching
     */
    public static function cached($data, int $maxAge, Request $request = null, bool $public = true): Response
    {
        if ($request) {
            $response = self::negotiate($data, $request);
        } else {
            $response = self::json($data);
        }
        
        return $response->withCache($maxAge, $public);
    }

    /**
     * Create CORS response
     */
    public static function cors($data, Request $request = null, array $corsOptions = []): Response
    {
        if ($request) {
            $response = self::negotiate($data, $request);
        } else {
            $response = self::json($data);
        }
        
        return $response->withCors($corsOptions);
    }

    /**
     * Create paginated response
     */
    public static function paginated(array $items, int $total, int $page, int $perPage, Request $request = null): Response
    {
        $data = [
            'items' => $items,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => ceil($total / $perPage),
                'has_next' => $page < ceil($total / $perPage),
                'has_prev' => $page > 1
            ]
        ];

        if ($request) {
            return self::negotiate($data, $request);
        }
        
        return self::json($data);
    }

    /**
     * Create response for file upload result
     */
    public static function uploadResult(bool $success, string $message = '', array $files = []): Response
    {
        $data = [
            'success' => $success,
            'message' => $message,
            'files' => $files
        ];

        return self::json($data, $success ? 200 : 400);
    }

    /**
     * Create response with custom headers for security
     */
    public static function secure($data, Request $request = null): Response
    {
        if ($request) {
            $response = self::negotiate($data, $request);
        } else {
            $response = self::json($data);
        }

        return $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('X-XSS-Protection', '1; mode=block')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Content-Security-Policy', "default-src 'self'");
    }

    /**
     * Create streaming response for large data
     */
    public static function stream(callable $callback, string $contentType = 'application/json'): Response
    {
        // Create a stream that will be populated by the callback
        $stream = new class($callback) implements \Psr\Http\Message\StreamInterface {
            private $callback;
            private string $buffer = '';
            private int $position = 0;
            private bool $generated = false;

            public function __construct(callable $callback)
            {
                $this->callback = $callback;
            }

            public function __toString(): string
            {
                $this->generateContent();
                return $this->buffer;
            }

            public function close(): void {}
            public function detach() { return null; }
            
            public function getSize(): ?int
            {
                $this->generateContent();
                return strlen($this->buffer);
            }

            public function tell(): int
            {
                return $this->position;
            }

            public function eof(): bool
            {
                $this->generateContent();
                return $this->position >= strlen($this->buffer);
            }

            public function isSeekable(): bool { return true; }
            
            public function seek(int $offset, int $whence = SEEK_SET): void
            {
                switch ($whence) {
                    case SEEK_SET: $this->position = $offset; break;
                    case SEEK_CUR: $this->position += $offset; break;
                    case SEEK_END: 
                        $this->generateContent();
                        $this->position = strlen($this->buffer) + $offset; 
                        break;
                }
            }

            public function rewind(): void { $this->position = 0; }
            public function isWritable(): bool { return false; }
            public function write(string $string): int { throw new \RuntimeException('Stream is not writable'); }
            public function isReadable(): bool { return true; }

            public function read(int $length): string
            {
                $this->generateContent();
                $data = substr($this->buffer, $this->position, $length);
                $this->position += strlen($data);
                return $data;
            }

            public function getContents(): string
            {
                $this->generateContent();
                $contents = substr($this->buffer, $this->position);
                $this->position = strlen($this->buffer);
                return $contents;
            }

            public function getMetadata(?string $key = null)
            {
                return $key === null ? [] : null;
            }

            private function generateContent(): void
            {
                if (!$this->generated) {
                    ob_start();
                    ($this->callback)();
                    $this->buffer = ob_get_clean();
                    $this->generated = true;
                }
            }
        };

        return new Response(200, ['Content-Type' => $contentType], $stream);
    }
}