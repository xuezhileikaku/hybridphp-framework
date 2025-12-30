<?php
namespace HybridPHP\Core\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Monolog\Level;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use function Amp\async;

/**
 * ELK (Elasticsearch) Handler for structured logging
 */
class ElkHandler extends AbstractProcessingHandler
{
    private array $config;
    private HttpClient $httpClient;
    private string $index;
    private string $type;
    private array $buffer = [];
    private int $bufferSize;

    public function __construct(array $config, $level = Level::Debug, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
        
        $this->config = $config;
        $this->httpClient = new HttpClient();
        $this->index = $config['index'] ?? 'hybridphp-logs';
        $this->type = $config['type'] ?? '_doc';
        $this->bufferSize = $config['buffer_size'] ?? 100;
    }

    /**
     * Write log record to Elasticsearch
     */
    protected function write(LogRecord $record): void
    {
        $document = $this->formatForElasticsearch($record);
        $this->buffer[] = $document;

        // Bulk insert when buffer is full
        if (count($this->buffer) >= $this->bufferSize) {
            $this->flushBuffer();
        }
    }

    /**
     * Format log record for Elasticsearch
     */
    private function formatForElasticsearch(LogRecord $record): array
    {
        return [
            '@timestamp' => $record->datetime->format('c'),
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
            'request' => $this->getRequestInfo(),
        ];
    }

    /**
     * Get current request information
     */
    private function getRequestInfo(): array
    {
        return [
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'uri' => $_SERVER['REQUEST_URI'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'referer' => $_SERVER['HTTP_REFERER'] ?? null,
        ];
    }

    /**
     * Flush buffer to Elasticsearch
     */
    private function flushBuffer(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        async(function() {
            try {
                $bulkData = $this->prepareBulkData($this->buffer);
                $this->buffer = [];

                $url = $this->getElasticsearchUrl() . '/_bulk';
                $request = new Request($url, 'POST');
                $request->setHeader('Content-Type', 'application/x-ndjson');
                $request->setBody($bulkData);

                if (isset($this->config['auth'])) {
                    $auth = base64_encode($this->config['auth']['username'] . ':' . $this->config['auth']['password']);
                    $request->setHeader('Authorization', 'Basic ' . $auth);
                }

                $response = $this->httpClient->request($request)->await();

                if ($response->getStatus() >= 400) {
                    error_log("ElkHandler: Failed to send logs to Elasticsearch. Status: " . $response->getStatus());
                }
            } catch (\Throwable $e) {
                error_log("ElkHandler error: " . $e->getMessage());
            }
        });
    }

    /**
     * Prepare bulk data for Elasticsearch
     */
    private function prepareBulkData(array $documents): string
    {
        $bulkData = '';
        
        foreach ($documents as $document) {
            $action = json_encode([
                'index' => [
                    '_index' => $this->index,
                    '_type' => $this->type,
                ]
            ]);
            
            $bulkData .= $action . "\n" . json_encode($document) . "\n";
        }
        
        return $bulkData;
    }

    /**
     * Get Elasticsearch URL
     */
    private function getElasticsearchUrl(): string
    {
        $host = $this->config['host'] ?? 'localhost';
        $port = $this->config['port'] ?? 9200;
        $scheme = $this->config['scheme'] ?? 'http';
        
        return "{$scheme}://{$host}:{$port}";
    }

    /**
     * Close handler and flush remaining buffer
     */
    public function close(): void
    {
        $this->flushBuffer();
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
}