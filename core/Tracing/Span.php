<?php

declare(strict_types=1);

namespace HybridPHP\Core\Tracing;

/**
 * Implementation of SpanInterface
 * 
 * Represents a single operation within a distributed trace
 */
class Span implements SpanInterface
{
    private SpanContextInterface $context;
    private string $operationName;
    private SpanKind $kind;
    private array $attributes = [];
    private array $events = [];
    private SpanStatus $status = SpanStatus::UNSET;
    private ?string $statusDescription = null;
    private float $startTime;
    private ?float $endTime = null;
    private ?string $parentSpanId;
    private bool $ended = false;
    /** @var callable|null */
    private mixed $onEnd = null;

    public function __construct(
        string $operationName,
        SpanContextInterface $context,
        ?string $parentSpanId = null,
        SpanKind $kind = SpanKind::INTERNAL,
        array $attributes = [],
        ?float $startTime = null
    ) {
        $this->operationName = $operationName;
        $this->context = $context;
        $this->parentSpanId = $parentSpanId;
        $this->kind = $kind;
        $this->attributes = $attributes;
        $this->startTime = $startTime ?? microtime(true);
    }

    /**
     * Set callback to be called when span ends
     */
    public function setOnEnd(callable $callback): void
    {
        $this->onEnd = $callback;
    }

    public function getSpanId(): string
    {
        return $this->context->getSpanId();
    }

    public function getTraceId(): string
    {
        return $this->context->getTraceId();
    }

    public function getContext(): SpanContextInterface
    {
        return $this->context;
    }

    public function getOperationName(): string
    {
        return $this->operationName;
    }

    public function setOperationName(string $name): SpanInterface
    {
        if (!$this->ended) {
            $this->operationName = $name;
        }
        return $this;
    }

    public function setAttribute(string $key, mixed $value): SpanInterface
    {
        if (!$this->ended) {
            $this->attributes[$key] = $this->normalizeAttributeValue($value);
        }
        return $this;
    }

    public function setAttributes(array $attributes): SpanInterface
    {
        if (!$this->ended) {
            foreach ($attributes as $key => $value) {
                $this->attributes[$key] = $this->normalizeAttributeValue($value);
            }
        }
        return $this;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function addEvent(string $name, array $attributes = [], ?float $timestamp = null): SpanInterface
    {
        if (!$this->ended) {
            $this->events[] = [
                'name' => $name,
                'timestamp' => $timestamp ?? microtime(true),
                'attributes' => array_map([$this, 'normalizeAttributeValue'], $attributes),
            ];
        }
        return $this;
    }

    public function getEvents(): array
    {
        return $this->events;
    }

    public function setStatus(SpanStatus $status, ?string $description = null): SpanInterface
    {
        if (!$this->ended) {
            // Only allow setting ERROR status or upgrading from UNSET
            if ($status === SpanStatus::ERROR || $this->status === SpanStatus::UNSET) {
                $this->status = $status;
                $this->statusDescription = $description;
            }
        }
        return $this;
    }

    public function getStatus(): SpanStatus
    {
        return $this->status;
    }

    public function getStatusDescription(): ?string
    {
        return $this->statusDescription;
    }

    public function recordException(\Throwable $exception, array $attributes = []): SpanInterface
    {
        $eventAttributes = array_merge([
            'exception.type' => get_class($exception),
            'exception.message' => $exception->getMessage(),
            'exception.stacktrace' => $exception->getTraceAsString(),
        ], $attributes);

        if ($exception->getCode() !== 0) {
            $eventAttributes['exception.code'] = $exception->getCode();
        }

        $this->addEvent('exception', $eventAttributes);
        $this->setStatus(SpanStatus::ERROR, $exception->getMessage());

        return $this;
    }

    public function end(?float $endTime = null): void
    {
        if ($this->ended) {
            return;
        }

        $this->endTime = $endTime ?? microtime(true);
        $this->ended = true;

        if ($this->onEnd !== null) {
            ($this->onEnd)($this);
        }
    }

    public function hasEnded(): bool
    {
        return $this->ended;
    }

    public function getStartTime(): float
    {
        return $this->startTime;
    }

    public function getEndTime(): ?float
    {
        return $this->endTime;
    }

    public function getDuration(): ?float
    {
        if ($this->endTime === null) {
            return null;
        }
        return $this->endTime - $this->startTime;
    }

    public function getParentSpanId(): ?string
    {
        return $this->parentSpanId;
    }

    public function isRoot(): bool
    {
        return $this->parentSpanId === null;
    }

    public function getKind(): SpanKind
    {
        return $this->kind;
    }

    public function toArray(): array
    {
        return [
            'trace_id' => $this->getTraceId(),
            'span_id' => $this->getSpanId(),
            'parent_span_id' => $this->parentSpanId,
            'operation_name' => $this->operationName,
            'kind' => $this->kind->value,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'duration' => $this->getDuration(),
            'status' => [
                'code' => $this->status->value,
                'description' => $this->statusDescription,
            ],
            'attributes' => $this->attributes,
            'events' => $this->events,
            'context' => $this->context->toArray(),
        ];
    }

    /**
     * Normalize attribute value to supported types
     */
    private function normalizeAttributeValue(mixed $value): mixed
    {
        if (is_scalar($value) || is_null($value)) {
            return $value;
        }

        if (is_array($value)) {
            return array_map([$this, 'normalizeAttributeValue'], $value);
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }
            return get_class($value);
        }

        return (string) $value;
    }
}
