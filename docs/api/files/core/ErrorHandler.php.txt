<?php
namespace HybridPHP\Core;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ErrorHandler
{
    protected ?LoggerInterface $logger = null;
    protected bool $debug = false;
    protected array $errorLevels = [
        E_ERROR => 'ERROR',
        E_WARNING => 'WARNING',
        E_PARSE => 'PARSE',
        E_NOTICE => 'NOTICE',
        E_CORE_ERROR => 'CORE_ERROR',
        E_CORE_WARNING => 'CORE_WARNING',
        E_COMPILE_ERROR => 'COMPILE_ERROR',
        E_COMPILE_WARNING => 'COMPILE_WARNING',
        E_USER_ERROR => 'USER_ERROR',
        E_USER_WARNING => 'USER_WARNING',
        E_USER_NOTICE => 'USER_NOTICE',
        E_STRICT => 'STRICT',
        E_RECOVERABLE_ERROR => 'RECOVERABLE_ERROR',
        E_DEPRECATED => 'DEPRECATED',
        E_USER_DEPRECATED => 'USER_DEPRECATED',
    ];

    public function __construct(?LoggerInterface $logger = null, bool $debug = false)
    {
        $this->logger = $logger ?: new NullLogger();
        $this->debug = $debug;
    }

    /**
     * Handle PHP errors
     */
    public function handleError(int $level, string $message, string $file = '', int $line = 0): bool
    {
        if (!(error_reporting() & $level)) {
            return false;
        }

        $levelName = $this->errorLevels[$level] ?? 'UNKNOWN';
        $context = [
            'level' => $level,
            'levelName' => $levelName,
            'file' => $file,
            'line' => $line,
        ];

        $this->logger->error("PHP {$levelName}: {$message}", $context);

        if ($this->debug || php_sapi_name() === 'cli') {
            $this->displayError($levelName, $message, $file, $line);
        }

        // Don't execute PHP internal error handler
        return true;
    }

    /**
     * Handle uncaught exceptions
     */
    public function handleException(\Throwable $exception): void
    {
        $context = [
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ];

        $this->logger->critical('Uncaught Exception: ' . $exception->getMessage(), $context);

        if ($this->debug || php_sapi_name() === 'cli') {
            $this->displayException($exception);
        }

        // Prevent further execution
        if (php_sapi_name() !== 'cli') {
            http_response_code(500);
            if (!$this->debug) {
                echo "Internal Server Error";
            }
        }
    }

    /**
     * Handle fatal errors during shutdown
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();
        
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $this->handleError(
                $error['type'],
                $error['message'],
                $error['file'],
                $error['line']
            );
        }
    }

    /**
     * Display error in CLI or debug mode
     */
    protected function displayError(string $level, string $message, string $file, int $line): void
    {
        if (php_sapi_name() === 'cli') {
            echo "\n[{$level}] {$message}\n";
            echo "File: {$file}:{$line}\n\n";
        } else {
            echo "<div style='background: #f8d7da; color: #721c24; padding: 10px; margin: 10px; border: 1px solid #f5c6cb; border-radius: 4px;'>";
            echo "<strong>[{$level}]</strong> {$message}<br>";
            echo "<small>File: {$file}:{$line}</small>";
            echo "</div>";
        }
    }

    /**
     * Display exception in CLI or debug mode
     */
    protected function displayException(\Throwable $exception): void
    {
        if (php_sapi_name() === 'cli') {
            echo "\n" . str_repeat('=', 80) . "\n";
            echo "UNCAUGHT EXCEPTION: " . get_class($exception) . "\n";
            echo str_repeat('=', 80) . "\n";
            echo "Message: " . $exception->getMessage() . "\n";
            echo "File: " . $exception->getFile() . ":" . $exception->getLine() . "\n";
            echo "\nStack Trace:\n";
            echo $exception->getTraceAsString() . "\n";
            echo str_repeat('=', 80) . "\n\n";
        } else {
            echo "<div style='background: #f8d7da; color: #721c24; padding: 15px; margin: 10px; border: 1px solid #f5c6cb; border-radius: 4px; font-family: monospace;'>";
            echo "<h3>Uncaught Exception: " . get_class($exception) . "</h3>";
            echo "<p><strong>Message:</strong> " . htmlspecialchars($exception->getMessage()) . "</p>";
            echo "<p><strong>File:</strong> " . htmlspecialchars($exception->getFile()) . ":" . $exception->getLine() . "</p>";
            echo "<details><summary>Stack Trace</summary><pre>" . htmlspecialchars($exception->getTraceAsString()) . "</pre></details>";
            echo "</div>";
        }
    }

    /**
     * Set logger instance
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Set debug mode
     */
    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    /**
     * Handle async exceptions (for AMPHP compatibility)
     */
    public function handleAsyncException(\Throwable $exception): void
    {
        // Log the async exception
        $this->logger->error('Async Exception: ' . $exception->getMessage(), [
            'exception' => get_class($exception),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'context' => 'async'
        ]);

        if ($this->debug || php_sapi_name() === 'cli') {
            echo "\n[ASYNC EXCEPTION] " . get_class($exception) . ": " . $exception->getMessage() . "\n";
            echo "File: " . $exception->getFile() . ":" . $exception->getLine() . "\n";
        }
    }

    /**
     * Create error context for logging
     */
    public function createContext(\Throwable $exception, array $additional = []): array
    {
        return array_merge([
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'code' => $exception->getCode(),
            'trace' => $exception->getTraceAsString(),
            'timestamp' => date('Y-m-d H:i:s'),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
        ], $additional);
    }
}
