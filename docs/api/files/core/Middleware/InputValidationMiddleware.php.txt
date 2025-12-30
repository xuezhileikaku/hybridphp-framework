<?php
namespace HybridPHP\Core\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use HybridPHP\Core\Http\Response;

/**
 * Async Input Validation and Data Cleaning Middleware
 * Validates and sanitizes all incoming data
 */
class InputValidationMiddleware extends AbstractMiddleware
{
    private array $config;
    private array $validationRules;
    private array $sanitizationRules;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'max_input_length' => 10000,
            'max_array_depth' => 10,
            'max_array_size' => 1000,
            'allowed_file_types' => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'txt', 'doc', 'docx'],
            'max_file_size' => 10485760, // 10MB
            'strict_validation' => false,
            'auto_sanitize' => true,
            'encoding' => 'UTF-8',
        ], $config);

        $this->initializeValidationRules();
        $this->initializeSanitizationRules();
    }

    /**
     * Initialize validation rules
     */
    private function initializeValidationRules(): void
    {
        $this->validationRules = [
            'email' => '/^[^\s@]+@[^\s@]+\.[^\s@]+$/',
            'url' => '/^https?:\/\/[^\s\/$.?#].[^\s]*$/i',
            'ip' => '/^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/',
            'phone' => '/^[\+]?[1-9][\d]{0,15}$/',
            'alphanumeric' => '/^[a-zA-Z0-9]+$/',
            'alpha' => '/^[a-zA-Z]+$/',
            'numeric' => '/^[0-9]+$/',
            'slug' => '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
        ];
    }

    /**
     * Initialize sanitization rules
     */
    private function initializeSanitizationRules(): void
    {
        $this->sanitizationRules = [
            'string' => FILTER_SANITIZE_FULL_SPECIAL_CHARS, // Replacement for deprecated FILTER_SANITIZE_STRING
            'email' => FILTER_SANITIZE_EMAIL,
            'url' => FILTER_SANITIZE_URL,
            'int' => FILTER_SANITIZE_NUMBER_INT,
            'float' => FILTER_SANITIZE_NUMBER_FLOAT,
            'encoded' => FILTER_SANITIZE_ENCODED,
            'special_chars' => FILTER_SANITIZE_SPECIAL_CHARS,
        ];
    }

    protected function before(ServerRequestInterface $request): ServerRequestInterface
    {
        // Validate request size and structure
        $this->validateRequestStructure($request);

        // Validate and sanitize query parameters
        $queryParams = $request->getQueryParams();
        if (!empty($queryParams)) {
            $this->validateArrayStructure($queryParams, 'query parameters');
            
            if ($this->config['auto_sanitize']) {
                $sanitizedQuery = $this->sanitizeArray($queryParams);
                $request = $request->withQueryParams($sanitizedQuery);
            }
        }

        // Validate and sanitize POST data
        $parsedBody = $request->getParsedBody();
        if (is_array($parsedBody)) {
            $this->validateArrayStructure($parsedBody, 'request body');
            
            if ($this->config['auto_sanitize']) {
                $sanitizedBody = $this->sanitizeArray($parsedBody);
                $request = $request->withParsedBody($sanitizedBody);
            }
        }

        // Validate uploaded files
        $uploadedFiles = $request->getUploadedFiles();
        if (!empty($uploadedFiles)) {
            $this->validateUploadedFiles($uploadedFiles);
        }

        // Validate headers
        $this->validateHeaders($request);

        return $request;
    }

    /**
     * Validate request structure and size
     *
     * @param ServerRequestInterface $request
     * @throws \InvalidArgumentException
     */
    private function validateRequestStructure(ServerRequestInterface $request): void
    {
        // Check content length
        $contentLength = $request->getHeaderLine('Content-Length');
        if ($contentLength && (int) $contentLength > $this->config['max_input_length']) {
            throw new \InvalidArgumentException('Request too large');
        }

        // Check encoding
        $contentType = $request->getHeaderLine('Content-Type');
        if ($contentType && str_contains($contentType, 'charset=')) {
            $charset = substr($contentType, strpos($contentType, 'charset=') + 8);
            if (strtoupper($charset) !== $this->config['encoding']) {
                throw new \InvalidArgumentException('Invalid character encoding');
            }
        }
    }

    /**
     * Validate array structure (depth and size)
     *
     * @param array $data
     * @param string $context
     * @param int $depth
     * @throws \InvalidArgumentException
     */
    private function validateArrayStructure(array $data, string $context, int $depth = 0): void
    {
        if ($depth > $this->config['max_array_depth']) {
            throw new \InvalidArgumentException("Array depth exceeded in {$context}");
        }

        if (count($data) > $this->config['max_array_size']) {
            throw new \InvalidArgumentException("Array size exceeded in {$context}");
        }

        foreach ($data as $key => $value) {
            // Validate key
            if (strlen((string) $key) > 255) {
                throw new \InvalidArgumentException("Array key too long in {$context}");
            }

            // Validate value
            if (is_array($value)) {
                $this->validateArrayStructure($value, $context, $depth + 1);
            } elseif (is_string($value) && strlen($value) > $this->config['max_input_length']) {
                throw new \InvalidArgumentException("String value too long in {$context}");
            }
        }
    }

    /**
     * Validate uploaded files
     *
     * @param array $uploadedFiles
     * @throws \InvalidArgumentException
     */
    private function validateUploadedFiles(array $uploadedFiles): void
    {
        foreach ($uploadedFiles as $file) {
            if ($file instanceof \Psr\Http\Message\UploadedFileInterface) {
                // Check file size
                if ($file->getSize() > $this->config['max_file_size']) {
                    throw new \InvalidArgumentException('File size exceeds limit');
                }

                // Check file type
                $filename = $file->getClientFilename();
                if ($filename) {
                    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    if (!in_array($extension, $this->config['allowed_file_types'])) {
                        throw new \InvalidArgumentException('File type not allowed');
                    }
                }

                // Check for upload errors
                if ($file->getError() !== UPLOAD_ERR_OK) {
                    throw new \InvalidArgumentException('File upload error: ' . $file->getError());
                }
            }
        }
    }

    /**
     * Validate headers
     *
     * @param ServerRequestInterface $request
     * @throws \InvalidArgumentException
     */
    private function validateHeaders(ServerRequestInterface $request): void
    {
        foreach ($request->getHeaders() as $name => $values) {
            // Check header name length
            if (strlen($name) > 255) {
                throw new \InvalidArgumentException('Header name too long');
            }

            // Check header values
            foreach ($values as $value) {
                if (strlen($value) > 8192) { // Common header value limit
                    throw new \InvalidArgumentException('Header value too long');
                }

                // Check for null bytes and control characters
                if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
                    throw new \InvalidArgumentException('Invalid characters in header value');
                }
            }
        }
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
                $sanitized[$sanitizedKey] = $this->sanitizeValue($value);
            }
        }
        
        return $sanitized;
    }

    /**
     * Sanitize a single value
     *
     * @param mixed $value
     * @return mixed
     */
    private function sanitizeValue($value)
    {
        if (is_string($value)) {
            return $this->sanitizeString($value);
        } elseif (is_numeric($value)) {
            return $value;
        } elseif (is_bool($value)) {
            return $value;
        } else {
            return $this->sanitizeString((string) $value);
        }
    }

    /**
     * Sanitize string value
     *
     * @param string $value
     * @return string
     */
    private function sanitizeString(string $value): string
    {
        // Remove null bytes
        $value = str_replace("\0", '', $value);
        
        // Remove control characters except tab, newline, and carriage return
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        
        // Normalize whitespace
        $value = preg_replace('/\s+/', ' ', $value);
        
        // Trim whitespace
        $value = trim($value);
        
        // Ensure proper encoding
        if (!mb_check_encoding($value, $this->config['encoding'])) {
            $value = mb_convert_encoding($value, $this->config['encoding'], 'auto');
        }
        
        return $value;
    }

    /**
     * Validate value against a rule
     *
     * @param mixed $value
     * @param string $rule
     * @return bool
     */
    public function validateValue($value, string $rule): bool
    {
        if (!isset($this->validationRules[$rule])) {
            return true; // Unknown rule, pass validation
        }

        $pattern = $this->validationRules[$rule];
        return preg_match($pattern, (string) $value) === 1;
    }

    /**
     * Apply sanitization rule to value
     *
     * @param mixed $value
     * @param string $rule
     * @return mixed
     */
    public function applySanitizationRule($value, string $rule)
    {
        if (!isset($this->sanitizationRules[$rule])) {
            return $this->sanitizeValue($value);
        }

        $filter = $this->sanitizationRules[$rule];
        return filter_var($value, $filter);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return parent::process($request, $handler);
        } catch (\InvalidArgumentException $e) {
            return $this->badRequestResponse($e->getMessage());
        }
    }

    /**
     * Return bad request response
     *
     * @param string $message
     * @return ResponseInterface
     */
    private function badRequestResponse(string $message = 'Bad Request'): ResponseInterface
    {
        return new Response(400, [
            'Content-Type' => 'application/json'
        ], json_encode([
            'error' => $message,
            'code' => 400
        ]));
    }
}