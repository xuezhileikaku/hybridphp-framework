<?php

declare(strict_types=1);

namespace HybridPHP\Core\Tracing\Exporter;

use Amp\Future;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use HybridPHP\Core\Tracing\SpanInterface;
use HybridPHP\Core\Tracing\SpanKind;
use Psr\Log\LoggerInterface;
use function Amp\async;

/**
 * Jaeger exporter using Thrift over HTTP
 * 
 * Sends spans to Jaeger collector using the Thrift binary protocol
 */
class JaegerExporter implements ExporterInterface
{
    private string $endpoint;
    private string $serviceName;
    private array $tags;
    private ?HttpClient $client = null;
    private ?LoggerInterface $logger;
    private bool $shutdown = false;

    public function __construct(
        string $endpoint,
        string $serviceName,
        array $tags = [],
        ?LoggerInterface $logger = null
    ) {
        $this->endpoint = rtrim($endpoint, '/');
        $this->serviceName = $serviceName;
        $this->tags = $tags;
        $this->logger = $logger;
    }

    /**
     * Create exporter with default Jaeger collector endpoint
     */
    public static function create(
        string $serviceName,
        string $host = 'localhost',
        int $port = 14268,
        array $tags = [],
        ?LoggerInterface $logger = null
    ): self {
        $endpoint = sprintf('http://%s:%d/api/traces', $host, $port);
        return new self($endpoint, $serviceName, $tags, $logger);
    }

    public function export(array $spans): Future
    {
        return async(function () use ($spans) {
            if ($this->shutdown || empty($spans)) {
                return true;
            }

            try {
                $payload = $this->buildPayload($spans);
                $client = $this->getClient();

                $request = new Request($this->endpoint, 'POST');
                $request->setHeader('Content-Type', 'application/x-thrift');
                $request->setBody($payload);

                $response = $client->request($request);
                $status = $response->getStatus();

                if ($status >= 200 && $status < 300) {
                    $this->log('debug', 'Exported spans to Jaeger', ['count' => count($spans)]);
                    return true;
                }

                $this->log('error', 'Failed to export spans to Jaeger', [
                    'status' => $status,
                    'body' => $response->getBody()->buffer(),
                ]);
                return false;

            } catch (\Throwable $e) {
                $this->log('error', 'Exception exporting spans to Jaeger', [
                    'error' => $e->getMessage(),
                ]);
                return false;
            }
        });
    }

    public function flush(): Future
    {
        return async(fn() => true);
    }

    public function shutdown(): Future
    {
        return async(function () {
            $this->shutdown = true;
            return true;
        });
    }

    /**
     * Build Jaeger-compatible JSON payload
     * 
     * Note: This uses JSON format for simplicity. For production,
     * consider using Thrift binary format for better performance.
     */
    private function buildPayload(array $spans): string
    {
        $jaegerSpans = [];

        foreach ($spans as $span) {
            $jaegerSpans[] = $this->convertSpan($span);
        }

        $batch = [
            'process' => [
                'serviceName' => $this->serviceName,
                'tags' => $this->convertTags($this->tags),
            ],
            'spans' => $jaegerSpans,
        ];

        return json_encode($batch);
    }

    /**
     * Convert span to Jaeger format
     */
    private function convertSpan(SpanInterface $span): array
    {
        $jaegerSpan = [
            'traceIdLow' => $this->hexToInt(substr($span->getTraceId(), 16, 16)),
            'traceIdHigh' => $this->hexToInt(substr($span->getTraceId(), 0, 16)),
            'spanId' => $this->hexToInt($span->getSpanId()),
            'operationName' => $span->getOperationName(),
            'startTime' => (int) ($span->getStartTime() * 1000000), // microseconds
            'duration' => (int) (($span->getDuration() ?? 0) * 1000000), // microseconds
            'tags' => $this->convertTags($span->getAttributes()),
            'logs' => $this->convertEvents($span->getEvents()),
        ];

        if ($span->getParentSpanId() !== null) {
            $jaegerSpan['parentSpanId'] = $this->hexToInt($span->getParentSpanId());
            $jaegerSpan['references'] = [
                [
                    'refType' => 'CHILD_OF',
                    'traceIdLow' => $jaegerSpan['traceIdLow'],
                    'traceIdHigh' => $jaegerSpan['traceIdHigh'],
                    'spanId' => $jaegerSpan['parentSpanId'],
                ],
            ];
        }

        // Add span kind tag
        $kind = $span instanceof \HybridPHP\Core\Tracing\Span ? $span->getKind() : SpanKind::INTERNAL;
        $jaegerSpan['tags'][] = [
            'key' => 'span.kind',
            'vType' => 'STRING',
            'vStr' => $kind->value,
        ];

        // Add status tags
        $status = $span->getStatus();
        if ($status->value !== 'unset') {
            $jaegerSpan['tags'][] = [
                'key' => 'otel.status_code',
                'vType' => 'STRING',
                'vStr' => strtoupper($status->value),
            ];

            if ($status->value === 'error') {
                $jaegerSpan['tags'][] = [
                    'key' => 'error',
                    'vType' => 'BOOL',
                    'vBool' => true,
                ];
            }
        }

        return $jaegerSpan;
    }

    /**
     * Convert attributes to Jaeger tags
     */
    private function convertTags(array $attributes): array
    {
        $tags = [];

        foreach ($attributes as $key => $value) {
            $tag = ['key' => $key];

            if (is_bool($value)) {
                $tag['vType'] = 'BOOL';
                $tag['vBool'] = $value;
            } elseif (is_int($value)) {
                $tag['vType'] = 'INT64';
                $tag['vLong'] = $value;
            } elseif (is_float($value)) {
                $tag['vType'] = 'FLOAT64';
                $tag['vDouble'] = $value;
            } else {
                $tag['vType'] = 'STRING';
                $tag['vStr'] = is_array($value) ? json_encode($value) : (string) $value;
            }

            $tags[] = $tag;
        }

        return $tags;
    }

    /**
     * Convert events to Jaeger logs
     */
    private function convertEvents(array $events): array
    {
        $logs = [];

        foreach ($events as $event) {
            $fields = [
                [
                    'key' => 'event',
                    'vType' => 'STRING',
                    'vStr' => $event['name'],
                ],
            ];

            foreach ($event['attributes'] ?? [] as $key => $value) {
                $fields[] = [
                    'key' => $key,
                    'vType' => 'STRING',
                    'vStr' => is_array($value) ? json_encode($value) : (string) $value,
                ];
            }

            $logs[] = [
                'timestamp' => (int) ($event['timestamp'] * 1000000),
                'fields' => $fields,
            ];
        }

        return $logs;
    }

    /**
     * Convert hex string to integer
     */
    private function hexToInt(string $hex): int
    {
        // Handle potential overflow for 64-bit values
        $hex = ltrim($hex, '0') ?: '0';
        return (int) hexdec($hex);
    }

    /**
     * Get HTTP client
     */
    private function getClient(): HttpClient
    {
        if ($this->client === null) {
            $this->client = HttpClientBuilder::buildDefault();
        }
        return $this->client;
    }

    /**
     * Log message if logger is available
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->log($level, $message, $context);
        }
    }
}
