<?php

declare(strict_types=1);

namespace HybridPHP\Core\Grpc;

/**
 * gRPC status codes (matching official gRPC status codes)
 */
enum Status: int
{
    case OK = 0;
    case CANCELLED = 1;
    case UNKNOWN = 2;
    case INVALID_ARGUMENT = 3;
    case DEADLINE_EXCEEDED = 4;
    case NOT_FOUND = 5;
    case ALREADY_EXISTS = 6;
    case PERMISSION_DENIED = 7;
    case RESOURCE_EXHAUSTED = 8;
    case FAILED_PRECONDITION = 9;
    case ABORTED = 10;
    case OUT_OF_RANGE = 11;
    case UNIMPLEMENTED = 12;
    case INTERNAL = 13;
    case UNAVAILABLE = 14;
    case DATA_LOSS = 15;
    case UNAUTHENTICATED = 16;

    /**
     * Get human-readable message for status code
     */
    public function getMessage(): string
    {
        return match ($this) {
            self::OK => 'OK',
            self::CANCELLED => 'Cancelled',
            self::UNKNOWN => 'Unknown error',
            self::INVALID_ARGUMENT => 'Invalid argument',
            self::DEADLINE_EXCEEDED => 'Deadline exceeded',
            self::NOT_FOUND => 'Not found',
            self::ALREADY_EXISTS => 'Already exists',
            self::PERMISSION_DENIED => 'Permission denied',
            self::RESOURCE_EXHAUSTED => 'Resource exhausted',
            self::FAILED_PRECONDITION => 'Failed precondition',
            self::ABORTED => 'Aborted',
            self::OUT_OF_RANGE => 'Out of range',
            self::UNIMPLEMENTED => 'Unimplemented',
            self::INTERNAL => 'Internal error',
            self::UNAVAILABLE => 'Service unavailable',
            self::DATA_LOSS => 'Data loss',
            self::UNAUTHENTICATED => 'Unauthenticated',
        };
    }

    /**
     * Check if status indicates success
     */
    public function isOk(): bool
    {
        return $this === self::OK;
    }

    /**
     * Check if status indicates an error
     */
    public function isError(): bool
    {
        return $this !== self::OK;
    }
}
