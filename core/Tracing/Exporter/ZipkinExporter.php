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
 * Zipkin exporter
 * 
 * Sends spans to Zipkin collector using JSON v2 format
 */
class ZipkinExporter implements ExporterInterface
{
    private string $endpoint;
    private string $serviceName;
    private ?string $localEndpoint;
    private ?HttpClient $client = null;
    private ?LoggerInterface $logger;
    private bool $shutdown = false;

    public function __construct(
        string $endpoint,
        string $serviceName,
        ?string $localEndpoint = null,
        ?LoggerInterface $logger = null
    ) {
        $this->endpoint = rtrim($endpoint, '/');
        $this->serviceName = $serviceName;
        $this->localEndpoint = $localEndpoint;
        $this->logger = $logger;
    }

    /**
     * Create exporter with default Zipkin endpoint
     */
    public static function create(
        string $serviceName,
        string $host = 'localhost',
        int $port = 9411,
        ?LoggerInterface $logger = null
    ): self {
        $endpoint = sprintf('http://%s:%d/api/v2/spans', $host, $port);
        return new self($endpoint, $serviceName, null, $logger);
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
                $request->setBody($payload);

                $response = $client->request($request);
                $status = $response->getStatus();

                if ($status >= 200 && $status < 300) {
                    $this->log('debug', 'Exported spans to Zipkin', ['count' => count($spans)]);
                    return true;
                }

                $this->log('error', 'Failed to export spans to Zipkin', [
                    'status' => $status,
                    'body' => $response->getBody()->buffer(),
                ]);
                return false;

            } catch (\Throwable $e) {
                $this->log('error', 'Exception exporting spans to Zipkin', [
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
     * Build Zipkin JSON v2 payload
     */
    private function buildPayload(array $spans): string
    {
        $zipkinSpans = [];

        foreach ($spans as $span) {
            $zipkinSpans[] = $this->convertSpan($span);
        }

        return json_encode($zipkinSpans);
    }

    /**
     * Convert span to Zipkin format
     */
    private function convertSpan(SpanInterface $span): array
    {
        $zipkinSpan = [
            'traceId' => $span->getTraceId(),
            'id' => $span->getSpanId(),
            'name' => $span->getOperationName(),
            'timestamp' => (int) ($span->getStartTime() * 1000000), // microseconds
            'duration' => (int) (($span->getDuration() ?? 0) * 1000000), // microseconds
            'localEndpoint' => [
                'serviceName' => $this->serviceName,
            ],
        ];

        // Add local endpoint IP if available
        if ($this->localEndpoint !== null) {
            $zipkinSpan['localEndpoint']['ipv4'] = $this->localEndpoint;
        }

        // Add parent span ID
        if ($span->getParentSpanId() !== null) {
            $zipkinSpan['parentId'] = $span->getParentSpanId();
        }

        // Convert span kind
        $kind = $span instanceof \HybridPHP\Core\Tracing\Span ? $span->getKind() : SpanKind::INTERNAL;
        $zipkinSpan['kind'] = $this->convertKind($kind);

        // Convert attributes to tags
        $tags = [];
        foreach ($span->getAttributes() as $key => $value) {
            $tags[$key] = is_array($value) ? json_encode($value) : (string) $value;
        }

        // Add status tags
        $status = $span->getStatus();
        if ($status === SpanStatus::ERROR) {
            $tags['error'] = 'true';
            $tags['otel.status_code'] = 'ERROR';
        } elseif ($status === SpanStatus::OK) {
            $tags['otel.status_code'] = 'OK';
        }

        if (!empty($tags)) {
            $zipkinSpan['tags'] = $tags;
        }

        // Convert events to annotations
        $annotations = [];
        foreach ($span->getEvents() as $event) {
            $annotations[] = [
                'timestamp' => (int) ($event['timestamp'] * 1000000),
                'value' => $event['name'],
            ];
        }

        if (!empty($annotations)) {
            $zipkinSpan['annotations'] = $annotations;
        }

        return $zipkinSpan;
    }

    /**
     * Convert OpenTelemetry span kind to Zipkin kind
     */
    private function convertKind(SpanKind $kind): string
    {
        return match ($kind) {
            SpanKind::SERVER => 'SERVER',
            SpanKind::CLIENT => 'CLIENT',
            SpanKind::PRODUCER => 'PRODUCER',
            SpanKind::CONSUMER => 'CONSUMER',
            default => 'CLIENT', // Zipkin doesn't have INTERNAL
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
