<?php

namespace HybridPHP\Core\Http;

/**
 * Request factory for creating Request objects from various sources
 */
class RequestFactory
{
    /**
     * Create Request from PHP globals ($_GET, $_POST, $_SERVER, etc.)
     */
    public static function fromGlobals(): Request
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = self::createUriFromGlobals();
        $headers = self::getHeadersFromGlobals();
        $body = self::getBodyFromGlobals();
        $serverParams = $_SERVER;

        $request = new Request($method, $uri, $headers, $body, '1.1', $serverParams);

        // Set parsed body
        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            $contentType = $headers['content-type'][0] ?? '';
            
            if (strpos($contentType, 'application/json') !== false) {
                $parsedBody = json_decode($body, true);
            } elseif (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
                parse_str($body, $parsedBody);
            } else {
                $parsedBody = $_POST;
            }
            
            $request = $request->withParsedBody($parsedBody);
        }

        // Set cookies
        $request = $request->withCookieParams($_COOKIE);

        // Set uploaded files
        if (!empty($_FILES)) {
            $uploadedFiles = self::createUploadedFilesFromGlobals($_FILES);
            $request = $request->withUploadedFiles($uploadedFiles);
        }

        return $request;
    }

    /**
     * Create URI from globals
     */
    private static function createUriFromGlobals(): Uri
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $port = $_SERVER['SERVER_PORT'] ?? ($scheme === 'https' ? 443 : 80);
        $path = $_SERVER['REQUEST_URI'] ?? '/';
        
        // Remove query string from path
        if (($pos = strpos($path, '?')) !== false) {
            $query = substr($path, $pos + 1);
            $path = substr($path, 0, $pos);
        } else {
            $query = $_SERVER['QUERY_STRING'] ?? '';
        }

        $uri = new Uri();
        $uri = $uri->withScheme($scheme)
                   ->withHost($host)
                   ->withPath($path)
                   ->withQuery($query);

        // Only set port if it's not the default for the scheme
        if (($scheme === 'http' && $port != 80) || ($scheme === 'https' && $port != 443)) {
            $uri = $uri->withPort($port);
        }

        return $uri;
    }

    /**
     * Get headers from globals
     */
    private static function getHeadersFromGlobals(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headerName = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$headerName] = [$value];
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) {
                $headerName = str_replace('_', '-', strtolower($key));
                $headers[$headerName] = [$value];
            }
        }

        return $headers;
    }

    /**
     * Get body from globals
     */
    private static function getBodyFromGlobals(): string
    {
        return file_get_contents('php://input') ?: '';
    }

    /**
     * Create uploaded files from $_FILES
     */
    private static function createUploadedFilesFromGlobals(array $files): array
    {
        $uploadedFiles = [];

        foreach ($files as $key => $file) {
            if (is_array($file['name'])) {
                // Multiple files
                $uploadedFiles[$key] = [];
                foreach ($file['name'] as $index => $name) {
                    $uploadedFiles[$key][] = UploadedFile::createFromGlobals([
                        'name' => $name,
                        'type' => $file['type'][$index],
                        'tmp_name' => $file['tmp_name'][$index],
                        'error' => $file['error'][$index],
                        'size' => $file['size'][$index]
                    ]);
                }
            } else {
                // Single file
                $uploadedFiles[$key] = UploadedFile::createFromGlobals($file);
            }
        }

        return $uploadedFiles;
    }

    /**
     * Create Request for testing purposes
     */
    public static function create(
        string $method = 'GET',
        string $uri = '/',
        array $headers = [],
        $body = null,
        array $serverParams = []
    ): Request {
        $uriObject = new Uri($uri);
        return new Request($method, $uriObject, $headers, $body, '1.1', $serverParams);
    }

    /**
     * Create JSON request for testing
     */
    public static function createJson(
        string $method,
        string $uri,
        array $data = [],
        array $headers = []
    ): Request {
        $headers['content-type'] = 'application/json';
        $body = json_encode($data);
        
        $request = self::create($method, $uri, $headers, $body);
        return $request->withParsedBody($data);
    }

    /**
     * Create form request for testing
     */
    public static function createForm(
        string $method,
        string $uri,
        array $data = [],
        array $headers = []
    ): Request {
        $headers['content-type'] = 'application/x-www-form-urlencoded';
        $body = http_build_query($data);
        
        $request = self::create($method, $uri, $headers, $body);
        return $request->withParsedBody($data);
    }

    /**
     * Create multipart request with files for testing
     */
    public static function createMultipart(
        string $method,
        string $uri,
        array $data = [],
        array $files = [],
        array $headers = []
    ): Request {
        $headers['content-type'] = 'multipart/form-data';
        
        $request = self::create($method, $uri, $headers);
        $request = $request->withParsedBody($data);
        
        if (!empty($files)) {
            $uploadedFiles = [];
            foreach ($files as $key => $file) {
                if (is_string($file)) {
                    // File path provided
                    $uploadedFiles[$key] = self::createUploadedFileFromPath($file);
                } elseif (is_array($file)) {
                    // File data provided
                    $uploadedFiles[$key] = self::createUploadedFileFromArray($file);
                }
            }
            $request = $request->withUploadedFiles($uploadedFiles);
        }
        
        return $request;
    }

    /**
     * Create UploadedFile from file path
     */
    private static function createUploadedFileFromPath(string $filePath): UploadedFile
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File not found: {$filePath}");
        }

        $stream = new class($filePath) implements \Psr\Http\Message\StreamInterface {
            private $resource;
            private string $filePath;

            public function __construct(string $filePath)
            {
                $this->filePath = $filePath;
                $this->resource = fopen($filePath, 'r');
            }

            public function __toString(): string
            {
                if (!$this->resource) return '';
                $this->rewind();
                return $this->getContents();
            }

            public function close(): void
            {
                if ($this->resource) {
                    fclose($this->resource);
                    $this->resource = null;
                }
            }

            public function detach() { $resource = $this->resource; $this->resource = null; return $resource; }
            public function getSize(): ?int { return $this->resource ? fstat($this->resource)['size'] ?? null : null; }
            public function tell(): int { return $this->resource ? ftell($this->resource) : 0; }
            public function eof(): bool { return $this->resource ? feof($this->resource) : true; }
            public function isSeekable(): bool { return (bool) $this->resource; }
            public function seek(int $offset, int $whence = SEEK_SET): void { if ($this->resource) fseek($this->resource, $offset, $whence); }
            public function rewind(): void { if ($this->resource) rewind($this->resource); }
            public function isWritable(): bool { return false; }
            public function write(string $string): int { throw new \RuntimeException('Stream is not writable'); }
            public function isReadable(): bool { return (bool) $this->resource; }
            public function read(int $length): string { return $this->resource ? fread($this->resource, $length) : ''; }
            public function getContents(): string { return $this->resource ? stream_get_contents($this->resource) : ''; }
            public function getMetadata(?string $key = null) { 
                if (!$this->resource) return $key === null ? [] : null;
                $meta = stream_get_meta_data($this->resource);
                return $key === null ? $meta : ($meta[$key] ?? null);
            }
        };

        return new UploadedFile(
            $stream,
            filesize($filePath),
            UPLOAD_ERR_OK,
            basename($filePath),
            mime_content_type($filePath) ?: 'application/octet-stream'
        );
    }

    /**
     * Create UploadedFile from array data
     */
    private static function createUploadedFileFromArray(array $fileData): UploadedFile
    {
        $content = $fileData['content'] ?? '';
        $name = $fileData['name'] ?? 'test.txt';
        $type = $fileData['type'] ?? 'text/plain';
        $size = $fileData['size'] ?? strlen($content);
        $error = $fileData['error'] ?? UPLOAD_ERR_OK;

        $stream = new class($content) implements \Psr\Http\Message\StreamInterface {
            private string $content;
            private int $position = 0;

            public function __construct(string $content) { $this->content = $content; }
            public function __toString(): string { return $this->content; }
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

        return new UploadedFile($stream, $size, $error, $name, $type);
    }
}