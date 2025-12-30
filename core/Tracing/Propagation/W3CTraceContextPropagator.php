<?php

declare(strict_types=1);

namespace HybridPHP\Core\Tracing\Propagation;

use HybridPHP\Core\Tracing\SpanContext;
use HybridPHP\Core\Tracing\SpanContextInterface;

/**
 * W3C Trace Context propagator
 * 
 * Implements the W3C Trace Context specification for context propagation
 * @see https://www.w3.org/TR/trace-context/
 */
class W3CTraceContextPropagator implements PropagatorInterface
{
    public const TRACEPARENT = 'traceparent';
    public const TRACESTATE = 'tracestate';
    public const VERSION = '00';

    public function fields(): array
    {
        return [self::TRACEPARENT, self::TRACESTATE];
    }

    public function inject(SpanContextInterface $context, array &$carrier): void
    {
        if (!$context->isValid()) {
            return;
        }

        // Format: {version}-{trace-id}-{parent-id}-{trace-flags}
        $traceparent = sprintf(
            '%s-%s-%s-%02x',
            self::VERSION,
            $context->getTraceId(),
            $context->getSpanId(),
            $context->getTraceFlags()
        );

        $carrier[self::TRACEPARENT] = $traceparent;

        if ($context->getTraceState() !== null) {
            $carrier[self::TRACESTATE] = $context->getTraceState();
        }
    }

    public function extract(array $carrier): ?SpanContextInterface
    {
        $traceparent = $this->getHeader($carrier, self::TRACEPARENT);
        
        if ($traceparent === null) {
            return null;
        }

        $parsed = $this->parseTraceparent($traceparent);
        
        if ($parsed === null) {
            return null;
        }

        $traceState = $this->getHeader($carrier, self::TRACESTATE);

        return new SpanContext(
            $parsed['trace_id'],
            $parsed['span_id'],
            $parsed['trace_flags'],
            $traceState,
            true // is remote
        );
    }

    /**
     * Parse traceparent header
     */
    private function parseTraceparent(string $traceparent): ?array
    {
        // Format: {version}-{trace-id}-{parent-id}-{trace-flags}
        $parts = explode('-', $traceparent);

        if (count($parts) !== 4) {
            return null;
        }

        [$version, $traceId, $spanId, $traceFlags] = $parts;

        // Validate version
        if ($version !== self::VERSION) {
            // Future versions may have different formats
            if (strlen($version) !== 2 || !ctype_xdigit($version)) {
                return null;
            }
        }

        // Validate trace ID (32 hex chars, not all zeros)
        if (strlen($traceId) !== 32 || !ctype_xdigit($traceId) || $traceId === str_repeat('0', 32)) {
            return null;
        }

        // Validate span ID (16 hex chars, not all zeros)
        if (strlen($spanId) !== 16 || !ctype_xdigit($spanId) || $spanId === str_repeat('0', 16)) {
            return null;
        }

        // Validate trace flags (2 hex chars)
        if (strlen($traceFlags) !== 2 || !ctype_xdigit($traceFlags)) {
            return null;
        }

        return [
            'trace_id' => strtolower($traceId),
            'span_id' => strtolower($spanId),
            'trace_flags' => hexdec($traceFlags),
        ];
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
