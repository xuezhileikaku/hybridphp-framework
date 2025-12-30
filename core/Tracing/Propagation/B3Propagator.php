<?php

declare(strict_types=1);

namespace HybridPHP\Core\Tracing\Propagation;

use HybridPHP\Core\Tracing\SpanContext;
use HybridPHP\Core\Tracing\SpanContextInterface;

/**
 * B3 propagator for Zipkin compatibility
 * 
 * Supports both single-header and multi-header B3 formats
 * @see https://github.com/openzipkin/b3-propagation
 */
class B3Propagator implements PropagatorInterface
{
    public const B3_SINGLE = 'b3';
    public const B3_TRACE_ID = 'x-b3-traceid';
    public const B3_SPAN_ID = 'x-b3-spanid';
    public const B3_PARENT_SPAN_ID = 'x-b3-parentspanid';
    public const B3_SAMPLED = 'x-b3-sampled';
    public const B3_FLAGS = 'x-b3-flags';

    private bool $useSingleHeader;

    public function __construct(bool $useSingleHeader = false)
    {
        $this->useSingleHeader = $useSingleHeader;
    }

    public function fields(): array
    {
        if ($this->useSingleHeader) {
            return [self::B3_SINGLE];
        }

        return [
            self::B3_TRACE_ID,
            self::B3_SPAN_ID,
            self::B3_PARENT_SPAN_ID,
            self::B3_SAMPLED,
            self::B3_FLAGS,
        ];
    }

    public function inject(SpanContextInterface $context, array &$carrier): void
    {
        if (!$context->isValid()) {
            return;
        }

        if ($this->useSingleHeader) {
            $this->injectSingleHeader($context, $carrier);
        } else {
            $this->injectMultiHeader($context, $carrier);
        }
    }

    public function extract(array $carrier): ?SpanContextInterface
    {
        // Try single header first
        $b3Single = $this->getHeader($carrier, self::B3_SINGLE);
        
        if ($b3Single !== null) {
            return $this->extractSingleHeader($b3Single);
        }

        // Fall back to multi-header
        return $this->extractMultiHeader($carrier);
    }

    /**
     * Inject using single B3 header
     */
    private function injectSingleHeader(SpanContextInterface $context, array &$carrier): void
    {
        // Format: {TraceId}-{SpanId}-{SamplingState}-{ParentSpanId}
        $sampled = $context->isSampled() ? '1' : '0';
        $carrier[self::B3_SINGLE] = sprintf(
            '%s-%s-%s',
            $context->getTraceId(),
            $context->getSpanId(),
            $sampled
        );
    }

    /**
     * Inject using multiple B3 headers
     */
    private function injectMultiHeader(SpanContextInterface $context, array &$carrier): void
    {
        $carrier[self::B3_TRACE_ID] = $context->getTraceId();
        $carrier[self::B3_SPAN_ID] = $context->getSpanId();
        $carrier[self::B3_SAMPLED] = $context->isSampled() ? '1' : '0';
    }

    /**
     * Extract from single B3 header
     */
    private function extractSingleHeader(string $b3): ?SpanContextInterface
    {
        // Handle special values
        if ($b3 === '0') {
            return null; // Not sampled, no trace
        }

        $parts = explode('-', $b3);
        $count = count($parts);

        if ($count < 2) {
            return null;
        }

        $traceId = $parts[0];
        $spanId = $parts[1];
        $sampled = true;

        // Validate trace ID
        if (!$this->isValidTraceId($traceId)) {
            return null;
        }

        // Validate span ID
        if (!$this->isValidSpanId($spanId)) {
            return null;
        }

        // Parse sampling state if present
        if ($count >= 3) {
            $sampledValue = $parts[2];
            if ($sampledValue === '0') {
                $sampled = false;
            } elseif ($sampledValue === 'd') {
                $sampled = true; // Debug flag
            }
        }

        // Normalize trace ID to 32 chars
        if (strlen($traceId) === 16) {
            $traceId = str_repeat('0', 16) . $traceId;
        }

        return new SpanContext(
            strtolower($traceId),
            strtolower($spanId),
            $sampled ? SpanContext::TRACE_FLAG_SAMPLED : SpanContext::TRACE_FLAG_NONE,
            null,
            true
        );
    }

    /**
     * Extract from multiple B3 headers
     */
    private function extractMultiHeader(array $carrier): ?SpanContextInterface
    {
        $traceId = $this->getHeader($carrier, self::B3_TRACE_ID);
        $spanId = $this->getHeader($carrier, self::B3_SPAN_ID);

        if ($traceId === null || $spanId === null) {
            return null;
        }

        if (!$this->isValidTraceId($traceId) || !$this->isValidSpanId($spanId)) {
            return null;
        }

        // Normalize trace ID to 32 chars
        if (strlen($traceId) === 16) {
            $traceId = str_repeat('0', 16) . $traceId;
        }

        // Parse sampling state
        $sampled = true;
        $sampledHeader = $this->getHeader($carrier, self::B3_SAMPLED);
        $flagsHeader = $this->getHeader($carrier, self::B3_FLAGS);

        if ($flagsHeader === '1') {
            $sampled = true; // Debug flag overrides
        } elseif ($sampledHeader === '0') {
            $sampled = false;
        }

        return new SpanContext(
            strtolower($traceId),
            strtolower($spanId),
            $sampled ? SpanContext::TRACE_FLAG_SAMPLED : SpanContext::TRACE_FLAG_NONE,
            null,
            true
        );
    }

    /**
     * Validate trace ID format
     */
    private function isValidTraceId(string $traceId): bool
    {
        $len = strlen($traceId);
        return ($len === 16 || $len === 32) && ctype_xdigit($traceId);
    }

    /**
     * Validate span ID format
     */
    private function isValidSpanId(string $spanId): bool
    {
        return strlen($spanId) === 16 && ctype_xdigit($spanId);
    }

    /**
     * Get header value from carrier (case-insensitive)
     */
    private function getHeader(array $carrier, string $name): ?string
    {
        $name = strtolower($name);
        
        foreach ($carrier as $key => $value) {
            if (strtolower($key) === $name) {
                return is_array($value) ? $value[0] : $value;
            }
        }

        return null;
    }
}
