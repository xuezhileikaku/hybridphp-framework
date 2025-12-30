<?php
namespace HybridPHP\Core\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Monolog\Level;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\connect;
use function Amp\async;

/**
 * Kafka Handler for streaming logs to Apache Kafka
 */
class KafkaHandler extends AbstractProcessingHandler
{
    private array $config;
    private string $topic;
    private array $brokers;
    private array $buffer = [];
    private int $bufferSize;
    private ?Socket $connection = null;

    public function __construct(array $config, $level = Level::Debug, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
        
        $this->config = $config;
        $this->topic = $config['topic'] ?? 'hybridphp-logs';
        $this->brokers = $config['brokers'] ?? ['localhost:9092'];
        $this->bufferSize = $config['buffer_size'] ?? 100;
    }

    /**
     * Write log record to Kafka
     */
    protected function write(LogRecord $record): void
    {
        $message = $this->formatForKafka($record);
        $this->buffer[] = $message;

        // Send batch when buffer is full
        if (count($this->buffer) >= $this->bufferSize) {
            $this->flushBuffer();
        }
    }

    /**
     * Format log record for Kafka
     */
    private function formatForKafka(LogRecord $record): array
    {
        return [
            'timestamp' => $record->datetime->getTimestamp() * 1000, // Kafka expects milliseconds
            'level' => $record->level->getName(),
            'message' => $record->message,
            'context' => $record->context,
            'extra' => $record->extra,
            'channel' => $record->channel,
            'application' => 'hybridphp',
            'environment' => $_ENV['APP_ENV'] ?? 'production',
            'server' => [
                'hostname' => gethostname(),
                'ip' => $_SERVER['SERVER_ADDR'] ?? '127.0.0.1',
                'process_id' => getmypid(),
            ],
            'trace_id' => $record->context['trace_id'] ?? null,
        ];
    }

    /**
     * Flush buffer to Kafka
     */
    private function flushBuffer(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        async(function() {
            try {
                $messages = $this->buffer;
                $this->buffer = [];

                $this->sendToKafka($messages)->await();
            } catch (\Throwable $e) {
                error_log("KafkaHandler error: " . $e->getMessage());
            }
        });
    }

    /**
     * Send messages to Kafka
     */
    private function sendToKafka(array $messages): \Amp\Future
    {
        return async(function() use ($messages) {
            foreach ($this->brokers as $broker) {
                try {
                    $connection = $this->connectToBroker($broker)->await();

                    foreach ($messages as $message) {
                        $kafkaMessage = $this->createKafkaMessage($message);
                        $connection->write($kafkaMessage)->await();
                    }

                    $connection->close();
                    break;
                } catch (\Throwable $e) {
                    error_log("Failed to send to Kafka broker {$broker}: " . $e->getMessage());
                    continue;
                }
            }
        });
    }

    /**
     * Connect to Kafka broker
     */
    private function connectToBroker(string $broker): \Amp\Future
    {
        return async(function() use ($broker) {
            $address = SocketAddress::fromString($broker);
            return connect($address)->await();
        });
    }

    /**
     * Create Kafka message in wire format
     * This is a simplified implementation - in production use a proper Kafka client
     */
    private function createKafkaMessage(array $message): string
    {
        $payload = json_encode($message);
        
        // Simplified Kafka message format
        // In reality, Kafka has a complex binary protocol
        $kafkaMessage = [
            'topic' => $this->topic,
            'partition' => 0,
            'key' => $message['trace_id'] ?? null,
            'value' => $payload,
            'timestamp' => $message['timestamp'],
        ];
        
        return json_encode($kafkaMessage) . "\n";
    }

    /**
     * Close handler and flush remaining buffer
     */
    public function close(): void
    {
        $this->flushBuffer();
        
        if ($this->connection) {
            $this->connection->close();
        }
        
        parent::close();
    }

    /**
     * Handle batch processing
     */
    public function handleBatch(array $records): void
    {
        foreach ($records as $record) {
            if ($this->isHandling($record)) {
                $this->handle($record);
            }
        }
    }

    /**
     * Get Kafka producer statistics
     */
    public function getStats(): array
    {
        return [
            'topic' => $this->topic,
            'brokers' => $this->brokers,
            'buffer_size' => count($this->buffer),
            'max_buffer_size' => $this->bufferSize,
        ];
    }
}