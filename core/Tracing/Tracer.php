<?php

declare(strict_types=1);

namespace HybridPHP\Core\Tracing;

use Amp\Future;
use HybridPHP\Core\Tracing\Exporter\ExporterInterface;
use HybridPHP\Core\Tracing\Propagation\CompositePropagator;
use HybridPHP\Core\Tracing\Propagation\PropagatorInterface;
use Psr\Log\LoggerInterface;
use function Amp\async;
use function Amp\delay;

/**
 * Main tracer implementation
 * 
 * Provides OpenTelemetry-compatible distributed tracing
 */
class Tracer implements TracingInterface
{
    private string $serviceName;
    private ?ExporterInterface $exporter;
    private PropagatorInterface $propagator;
    private ?LoggerInterface $logger;
    private array $config;

    /** @var Span[] */
    private array $pendingSpans = [];
    
    /** @var Span[] */
    private array $spanStack = [];
    
    private ?SpanContextInterface $currentContext = null;
    private bool $running = false;
    private bool $shutdown = false;

    public function __construct(
        string $serviceName,
        ?ExporterInterface $exporter = null,
        ?PropagatorInterface $propagator = null,
        ?LoggerInterface $logger = null,
        array $config = []
    ) {
        $this->serviceName = $serviceName;
        $this->exporter = $exporter;
        $this->propagator = $propagator ?? CompositePropagator::createDefault();
        $this->logger = $logger;
        $this->config = array_merge([
            'batch_size' => 100,
            'flush_interval' => 5.0, // seconds
            'max_queue_size' => 2048,
            'sampling_rate' => 1.0, // 100% sampling
        ], $config);
    }

    /**
     * Start the background span processor
     */
    public function start(): Future
    {
        return async(function () {
            if ($this->running) {
                return;
            }

            $this->running = true;
            $this->log('info', 'Tracer started', ['service' => $this->serviceName]);

            while ($this->running && !$this->shutdown) {
                try {
                    if (count($this->pendingSpans) >= $this->config['batch_size']) {
                        $this->flushPendingSpans();
                    }
                } catch (\Throwable $e) {
                    $this->log('error', 'Error in tracer loop', ['error' => $e->getMessage()]);
                }

                delay($this->config['flush_interval']);
            }
        });
    }

    /**
     * Stop the tracer
     */
    public function stop(): void
    {
        $this->running = false;
    }

    public function startTrace(string $operationName, array $attributes = []): SpanInterface
    {
        // Apply sampling
        if (!$this->shouldSample()) {
            return $this->createNoopSpan($operationName);
        }

        $context = SpanContext::create();
        $this->currentContext = $context;

        return $this->createSpan($operationName, $context, null, SpanKind::SERVER, $attributes);
    }

    public function startSpan(
        string $operationName,
        array $attributes = [],
        ?SpanContextInterface $parentContext = null
    ): SpanInterface {
        // Use provided parent context, current context, or create new
        $parent = $parentContext ?? $this->currentContext;

        if ($parent === null) {
            return $this->startTrace($operationName, $attributes);
        }

        // Apply sampling based on parent
        if (!$parent->isSampled()) {
            return $this->createNoopSpan($operationName);
        }

        $context = $parent->withSpanId(SpanContext::generateSpanId());

        return $this->createSpan(
            $operationName,
            $context,
            $parent->getSpanId(),
            SpanKind::INTERNAL,
            $attributes
        );
    }

    public function getCurrentSpan(): ?SpanInterface
    {
        return end($this->spanStack) ?: null;
    }

    public function getContext(): ?SpanContextInterface
    {
        return $this->currentContext;
    }

    public function extract(array $carrier): ?SpanContextInterface
    {
        return $this->propagator->extract($carrier);
    }

    public function inject(array &$carrier): void
    {
        if ($this->currentContext !== null) {
            $this->propagator->inject($this->currentContext, $carrier);
        }
    }

    public function flush(): void
    {
        $this->flushPendingSpans();
    }

    public function shutdown(): void
    {
        $this->shutdown = true;
        $this->running = false;
        
        // Flush remaining spans
        $this->flushPendingSpans();

        if ($this->exporter !== null) {
            $this->exporter->shutdown()->await();
        }

        $this->log('info', 'Tracer shutdown complete');
    }

    /**
     * Create a span with automatic lifecycle management
     */
    private function createSpan(
        string $operationName,
        SpanContextInterface $context,
        ?string $parentSpanId,
        SpanKind $kind,
        array $attributes
    ): Span {
        $span = new Span($operationName, $context, $parentSpanId, $kind, $attributes);
        
        // Set up end callback
        $span->setOnEnd(function (Span $endedSpan) {
            $this->onSpanEnd($endedSpan);
        });

        // Push to stack
        $this->spanStack[] = $span;
        $this->currentContext = $context;

        return $span;
    }

    /**
     * Handle span end
     */
    private function onSpanEnd(Span $span): void
    {
        // Remove from stack
        $index = array_search($span, $this->spanStack, true);
        if ($index !== false) {
            array_splice($this->spanStack, $index, 1);
        }

        // Update current context
        if (!empty($this->spanStack)) {
            $currentSpan = end($this->spanStack);
            $this->currentContext = $currentSpan->getContext();
        } elseif ($span->isRoot()) {
            $this->currentContext = null;
        }

        // Queue for export
        $this->queueSpan($span);
    }

    /**
     * Queue span for export
     */
    private function queueSpan(Span $span): void
    {
        if (count($this->pendingSpans) >= $this->config['max_queue_size']) {
            $this->log('warning', 'Span queue full, dropping oldest span');
            array_shift($this->pendingSpans);
        }

        $this->pendingSpans[] = $span;

        // Flush if batch size reached
        if (count($this->pendingSpans) >= $this->config['batch_size']) {
            $this->flushPendingSpans();
        }
    }

    /**
     * Flush pending spans to exporter
     */
    private function flushPendingSpans(): void
    {
        if (empty($this->pendingSpans) || $this->exporter === null) {
            return;
        }

        $spans = $this->pendingSpans;
        $this->pendingSpans = [];

        try {
            $this->exporter->export($spans)->await();
        } catch (\Throwable $e) {
            $this->log('error', 'Failed to export spans', [
                'error' => $e->getMessage(),
                'count' => count($spans),
            ]);
        }
    }

    /**
     * Check if request should be sampled
     */
    private function shouldSample(): bool
    {
        if ($this->config['sampling_rate'] >= 1.0) {
            return true;
        }

        if ($this->config['sampling_rate'] <= 0.0) {
            return false;
        }

        return (mt_rand() / mt_getrandmax()) < $this->config['sampling_rate'];
    }

    /**
     * Create a no-op span for unsampled traces
     */
    private function createNoopSpan(string $operationName): SpanInterface
    {
        $context = new SpanContext(
            SpanContext::generateTraceId(),
            SpanContext::generateSpanId(),
            SpanContext::TRACE_FLAG_NONE
        );

        return new Span($operationName, $context);
    }

    /**
     * Log message if logger is available
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->log($level, '[Tracer] ' . $message, $context);
        }
    }

    /**
     * Get service name
     */
    public function getServiceName(): string
    {
        return $this->serviceName;
    }

    /**
     * Get propagator
     */
    public function getPropagator(): PropagatorInterface
    {
        return $this->propagator;
    }

    /**
     * Get pending span count
     */
    public function getPendingSpanCount(): int
    {
        return count($this->pendingSpans);
    }
}
