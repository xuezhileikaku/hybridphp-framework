<?php

declare(strict_types=1);

namespace HybridPHP\Core\Tracing;

/**
 * Span status enum following OpenTelemetry specification
 */
enum SpanStatus: string
{
    case UNSET = 'unset';
    case OK = 'ok';
    case ERROR = 'error';

    /**
     * Get the status code as integer
     */
    public function getCode(): int
    {
        return match ($this) {
            self::UNSET => 0,
            self::OK => 1,
            self::ERROR => 2,
        };
    }

    /**
     * Create from integer code
     */
    public static function fromCode(int $code): self
    {
        return match ($code) {
            1 => self::OK,
            2 => self::ERROR,
            default => self::UNSET,
        };
    }
}
