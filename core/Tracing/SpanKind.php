<?php

declare(strict_types=1);

namespace HybridPHP\Core\Tracing;

/**
 * Span kind enum following OpenTelemetry specification
 */
enum SpanKind: string
{
    case INTERNAL = 'internal';
    case SERVER = 'server';
    case CLIENT = 'client';
    case PRODUCER = 'producer';
    case CONSUMER = 'consumer';

    /**
     * Get the kind code as integer
     */
    public function getCode(): int
    {
        return match ($this) {
            self::INTERNAL => 0,
            self::SERVER => 1,
            self::CLIENT => 2,
            self::PRODUCER => 3,
            self::CONSUMER => 4,
        };
    }

    /**
     * Create from integer code
     */
    public static function fromCode(int $code): self
    {
        return match ($code) {
            1 => self::SERVER,
            2 => self::CLIENT,
            3 => self::PRODUCER,
            4 => self::CONSUMER,
            default => self::INTERNAL,
        };
    }
}
