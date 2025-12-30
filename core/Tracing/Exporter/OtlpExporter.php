<?php

declare(strict_types=1);

namespace HybridPHP\Core\Tracing\Exporter;

use Amp\Future;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use HybridPHP\Core\Tracing\SpanInterface;
use HybridPHP\Core\Tracing\SpanKind;
use HybridPHP\Core\Tracing\SpanStatus;
use Psr\Log\LoggerInterface;
use function Amp\async;

/**
 * OTLP (OpenTelemetry Protocol) exporter
 * 
 * Sends spans using the OpenTelemetry Protocol over HTTP/JSON
 */
class OtlpExporter implements ExporterInterface
{
    private string $endpoint;
    private string $serviceName;
    private array $resourceAttributes;
    private array $headers;
    private ?HttpClient $client = null;
    private ?LoggerInterface $logger;
    private bool $shutdown = false;

    public function __construct(
        string $endpoint,
        string $serviceName,
        array $resourceAttributes = [],
        array $headers = [],
        ?LoggerInterface $logger = null
    ) {
        $this->endpoint = rtrim($endpoint, '/');
        $this->serviceName = $serviceName;
        $this->resourceAttributes = $resourceAttributes;
        $this->headers = $headers;
        $this->logger = $logger;
    }

    /**
     * Create exporter with default OTLP endpoint
     */
    public static function create(
        string $serviceName,
        string $host = 'localhost',
        int $port = 4318,
        array $headers = [],
        ?LoggerInterface $logger = null
    ): self {
        $endpoint = sprintf('http://%s:%d/v1/traces', $host, $port);
        return new self($endpoint, $serviceName, [], $headers, $logger);
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
                $request->setHeader('Content-Type', 'application/json');
                
                foreach ($this->headers as $name => $value) {
                    $request->setHeader($name, $value);
                }
                
                $request->setBody($payload);

                $response = $client->request($request);
                $status = $response->getStatus();

                if ($status >= 200 && $status < 300) {
                    $this->log('debug', 'Exported spans via OTLP', ['count' => count($spans)]);
                    return true;
                }

                $this->log('error', 'Failed to export spans via OTLP', [
                    'status' => $status,
                    'body' => $response->getBody()->buffer(),
                ]);
                return false;

            } catch (\Throwable $e) {
                $this->log('error', 'Exception exporting spans via OTLP', [
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
     * Build OTLP JSON payload
     */
    private function buildPayload(array $spans): string
    {
        $resourceSpans = [
            'resourceSpans' => [
                [
                    'resource' => [
                        'attributes' => $this->buildResourceAttributes(),
                    ],
                    'scopeSpans' => [
                        [
                            'scope' => [
                                'name' => 'hybridphp',
                                'version' => '1.0.0',
                            ],
                            'spans' => array_map([$this, 'convertSpan'], $spans),
                        ],
                    ],
                ],
            ],
        ];

        return json_encode($resourceSpans);
    }

    /**
     * Build resource attributes
     */
    private function buildResourceAttributes(): array
    {
        $attributes = [
            [
                'key' => 'service.name',
                'value' => ['stringValue' => $this->serviceName],
            ],
            [
                'key' => 'telemetry.sdk.name',
                'value' => ['stringValue' => 'hybridphp'],
            ],
            [
                'key' => 'telemetry.sdk.language',
                'value' => ['stringValue' => 'php'],
            ],
            [
                'key' => 'telemetry.sdk.version',
                'value' => ['stringValue' => '1.0.0'],
            ],
        ];

        foreach ($this->resourceAttributes as $key => $value) {
            $attributes[] = [
                'key' => $key,
                'value' => $this->convertAttributeValue($value),
            ];
        }

        return $attributes;
    }

    /**
     * Convert span to OTLP format
     */
    private function convertSpan(SpanInterface $span): array
    {
        $otlpSpan = [
            'traceId' => base64_encode(hex2bin($span->getTraceId())),
            'spanId' => base64_encode(hex2bin($span->getSpanId())),
            'name' => $span->getOperationName(),
            'kind' => $this->convertKind($span instanceof \HybridPHP\Core\Tracing\Span ? $span->getKind() : SpanKind::INTERNAL),
            'startTimeUnixNano' => (string) ((int) ($span->getStartTime() * 1000000000)),
            'endTimeUnixNano' => (string) ((int) (($span->getEndTime() ?? $span->getStartTime()) * 1000000000)),
            'attributes' => [],
            'events' => [],
            'status' => $this->convertStatus($span->getStatus()),
        ];

        // Add parent span ID
        if ($span->getParentSpanId() !== null) {
            $otlpSpan['parentSpanId'] = base64_encode(hex2bin($span->getParentSpanId()));
        }

        // Convert attributes
        foreach ($span->getAttributes() as $key => $value) {
            $otlpSpan['attributes'][] = [
                'key' => $key,
                'value' => $this->convertAttributeValue($value),
            ];
        }

        // Convert events
        foreach ($span->getEvents() as $event) {
            $otlpEvent = [
                'name' => $event['name'],
                'timeUnixNano' => (string) ((int) ($event['timestamp'] * 1000000000)),
                'attributes' => [],
            ];

            foreach ($event['attributes'] ?? [] as $key => $value) {
                $otlpEvent['attributes'][] = [
                    'key' => $key,
                    'value' => $this->convertAttributeValue($value),
                ];
            }

            $otlpSpan['events'][] = $otlpEvent;
        }

        return $otlpSpan;
    }

    /**
     * Convert attribute value to OTLP format
     */
    private function convertAttributeValue(mixed $value): array
    {
        if (is_bool($value)) {
            return ['boolValue' => $value];
        }
        
        if (is_int($value)) {
            return ['intValue' => (string) $value];
        }
        
        if (is_float($value)) {
            return ['doubleValue' => $value];
        }
        
        if (is_array($value)) {
            $arrayValue = ['values' => []];
            foreach ($value as $item) {
                $arrayValue['values'][] = $this->convertAttributeValue($item);
            }
            return ['arrayValue' => $arrayValue];
        }

        return ['stringValue' => (string) $value];
    }

    /**
     * Convert span kind to OTLP format
     */
    private function convertKind(SpanKind $kind): int
    {
        return match ($kind) {
            SpanKind::INTERNAL => 1,
            SpanKind::SERVER => 2,
            SpanKind::CLIENT => 3,
            SpanKind::PRODUCER => 4,
            SpanKind::CONSUMER => 5,
        };
    }

    /**
     * Convert status to OTLP format
     */
    private function convertStatus(SpanStatus $status): array
    {
        return match ($status) {
            SpanStatus::OK => ['code' => 1],
            SpanStatus::ERROR => ['code' => 2],
            default => ['code' => 0],
        };
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
