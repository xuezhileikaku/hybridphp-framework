<?php
namespace HybridPHP\Core\Logging;

use Monolog\Logger;
use Monolog\Handler\HandlerInterface;
use Monolog\Formatter\JsonFormatter;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Amp\Future;
use Amp\DeferredFuture;
use function Amp\async;
use function Amp\delay;

/**
 * Async Logger with batch processing and non-blocking writes
 */
class AsyncLogger implements LoggerInterface
{
    private Logger $monolog;
    private array $buffer = [];
    private int $bufferSize;
    private float $flushInterval;
    private bool $autoFlush;
    private ?Future $flushTask = null;
    private bool $running = false;
    private array $handlers = [];
    private string $traceId = '';
    private array $context = [];

    public function __construct(
        string $name = 'hybridphp',
        int $bufferSize = 1000,
        float $flushInterval = 5.0,
        bool $autoFlush = true
    ) {
        $this->monolog = new Logger($name);
        $this->bufferSize = $bufferSize;
        $this->flushInterval = $flushInterval;
        $this->autoFlush = $autoFlush;
        
        // Generate initial trace ID
        $this->generateTraceId();
        
        if ($this->autoFlush) {
            $this->startAutoFlush();
        }
    }

    /**
     * Add a handler to the logger
     */
    public function addHandler(HandlerInterface $handler): self
    {
        $this->handlers[] = $handler;
        $this->monolog->pushHandler($handler);
        return $this;
    }

    /**
     * Set global context that will be added to all log entries
     */
    public function setContext(array $context): self
    {
        $this->context = $context;
        return $this;
    }

    /**
     * Set trace ID for distributed tracing
     */
    public function setTraceId(string $traceId): self
    {
        $this->traceId = $traceId;
        return $this;
    }

    /**
     * Generate a new trace ID
     */
    public function generateTraceId(): string
    {
        $this->traceId = bin2hex(random_bytes(16));
        return $this->traceId;
    }

    /**
     * Get current trace ID
     */
    public function getTraceId(): string
    {
        return $this->traceId;
    }

    /**
     * Log a message (PSR-3 compatible)
     */
    public function log($level, $message, array $context = []): void
    {
        $this->addToBuffer($level, $message, $context);
    }

    /**
     * Add log entry to buffer
     */
    private function addToBuffer(string $level, string $message, array $context = []): void
    {
        $logEntry = [
            'level' => $level,
            'message' => $message,
            'context' => array_merge($this->context, $context, [
                'trace_id' => $this->traceId,
                'timestamp' => microtime(true),
                'memory_usage' => memory_get_usage(true),
                'process_id' => getmypid(),
            ]),
            'timestamp' => time(),
            'datetime' => new \DateTimeImmutable(),
        ];

        $this->buffer[] = $logEntry;

        // Flush if buffer is full
        if (count($this->buffer) >= $this->bufferSize) {
            $this->flushAsync();
        }
    }

    /**
     * Start auto-flush task
     */
    private function startAutoFlush(): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;
        $this->flushTask = async(function() {
            while ($this->running) {
                delay($this->flushInterval);
                if (!empty($this->buffer)) {
                    $this->flushAsync()->await();
                }
            }
        });
    }

    /**
     * Flush buffer asynchronously
     */
    public function flushAsync(): Future
    {
        return async(function() {
            if (empty($this->buffer)) {
                return;
            }

            $entries = $this->buffer;
            $this->buffer = [];

            // Process entries in batches to avoid blocking
            $batchSize = 100;
            $batches = array_chunk($entries, $batchSize);

            foreach ($batches as $batch) {
                $this->processBatch($batch)->await();
                
                // Small delay between batches to prevent blocking
                if (count($batches) > 1) {
                    delay(0.001); // 1ms
                }
            }
        });
    }

    /**
     * Process a batch of log entries
     */
    private function processBatch(array $batch): Future
    {
        return async(function() use ($batch) {
            foreach ($batch as $entry) {
                try {
                    $this->monolog->log(
                        $entry['level'],
                        $entry['message'],
                        $entry['context']
                    );
                } catch (\Throwable $e) {
                    // Fallback logging to prevent infinite loops
                    error_log("AsyncLogger error: " . $e->getMessage());
                }
            }
        });
    }

    /**
     * Force flush all buffered entries
     */
    public function flush(): Future
    {
        return $this->flushAsync();
    }

    /**
     * Stop the logger and flush remaining entries
     */
    public function stop(): Future
    {
        return async(function() {
            $this->running = false;
            
            if ($this->flushTask) {
                // Wait for flush task to complete
                delay(0.1);
            }
            
            // Flush any remaining entries
            $this->flushAsync()->await();
        });
    }

    // PSR-3 Logger Interface methods
    public function emergency($message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }

    public function alert($message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL, $message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    public function notice($message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE, $message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->log(LogLevel::INFO, $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }

    /**
     * Get buffer statistics
     */
    public function getStats(): array
    {
        return [
            'buffer_size' => count($this->buffer),
            'max_buffer_size' => $this->bufferSize,
            'flush_interval' => $this->flushInterval,
            'auto_flush' => $this->autoFlush,
            'running' => $this->running,
            'handlers_count' => count($this->handlers),
            'trace_id' => $this->traceId,
        ];
    }
}