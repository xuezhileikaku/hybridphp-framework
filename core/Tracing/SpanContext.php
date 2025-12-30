<?php

declare(strict_types=1);

namespace HybridPHP\Core\Tracing;

/**
 * Implementation of SpanContextInterface
 * 
 * Contains trace and span identifiers for context propagation
 */
class SpanContext implements SpanContextInterface
{
    public const TRACE_FLAG_SAMPLED = 0x01;
    public const TRACE_FLAG_NONE = 0x00;

    private string $traceId;
    private string $spanId;
    private int $traceFlags;
    private ?string $traceState;
    private bool $isRemote;

    public function __construct(
        string $traceId,
        string $spanId,
        int $traceFlags = self::TRACE_FLAG_SAMPLED,
        ?string $traceState = null,
        bool $isRemote = false
    ) {
        $this->traceId = $traceId;
        $this->spanId = $spanId;
        $this->traceFlags = $traceFlags;
        $this->traceState = $traceState;
        $this->isRemote = $isRemote;
    }

    /**
     * Create a new context with generated IDs
     */
    public static function create(?string $traceId = null, ?string $spanId = null): self
    {
        return new self(
            $traceId ?? self::generateTraceId(),
            $spanId ?? self::generateSpanId(),
            self::TRACE_FLAG_SAMPLED
        );
    }

    /**
     * Create an invalid/empty context
     */
    public static function createInvalid(): self
    {
        return new self(
            str_repeat('0', 32),
            str_repeat('0', 16),
            self::TRACE_FLAG_NONE
        );
    }

    /**
     * Generate a 128-bit trace ID (32 hex characters)
     */
    public static function generateTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Generate a 64-bit span ID (16 hex characters)
     */
    public static function generateSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    public function getTraceId(): string
    {
        return $this->traceId;
    }

    public function getSpanId(): string
    {
        return $this->spanId;
    }

    public function getTraceFlags(): int
    {
        return $this->traceFlags;
    }

    public function getTraceState(): ?string
    {
        return $this->traceState;
    }

    public function isValid(): bool
    {
        return $this->traceId !== str_repeat('0', 32)
            && $this->spanId !== str_repeat('0', 16);
    }

    public function isRemote(): bool
    {
        return $this->isRemote;
    }

    public function isSampled(): bool
    {
        return ($this->traceFlags & self::TRACE_FLAG_SAMPLED) === self::TRACE_FLAG_SAMPLED;
    }

    public function withSpanId(string $spanId): SpanContextInterface
    {
        return new self(
            $this->traceId,
            $spanId,
            $this->traceFlags,
            $this->traceState,
            false
        );
    }

    public function toArray(): array
    {
        return [
            'trace_id' => $this->traceId,
            'span_id' => $this->spanId,
            'trace_flags' => $this->traceFlags,
            'trace_state' => $this->traceState,
            'is_remote' => $this->isRemote,
            'is_sampled' => $this->isSampled(),
        ];
    }
}
