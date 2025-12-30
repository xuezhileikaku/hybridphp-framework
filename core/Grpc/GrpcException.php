<?php

declare(strict_types=1);

namespace HybridPHP\Core\Grpc;

use Exception;
use Throwable;

/**
 * gRPC exception with status code support
 */
class GrpcException extends Exception
{
    protected Status $status;
    protected array $details;
    protected array $metadata;

    public function __construct(
        string $message = '',
        Status $status = Status::UNKNOWN,
        array $details = [],
        array $metadata = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $status->value, $previous);
        $this->status = $status;
        $this->details = $details;
        $this->metadata = $metadata;
    }

    /**
     * Get the gRPC status code
     */
    public function getStatus(): Status
    {
        return $this->status;
    }

    /**
     * Get error details
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /**
     * Get error metadata
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Create exception for invalid argument
     */
    public static function invalidArgument(string $message, array $details = []): self
    {
        return new self($message, Status::INVALID_ARGUMENT, $details);
    }

    /**
     * Create exception for not found
     */
    public static function notFound(string $message, array $details = []): self
    {
        return new self($message, Status::NOT_FOUND, $details);
    }

    /**
     * Create exception for unimplemented
     */
    public static function unimplemented(string $message, array $details = []): self
    {
        return new self($message, Status::UNIMPLEMENTED, $details);
    }

    /**
     * Create exception for internal error
     */
    public static function internal(string $message, array $details = []): self
    {
        return new self($message, Status::INTERNAL, $details);
    }

    /**
     * Create exception for permission denied
     */
    public static function permissionDenied(string $message, array $details = []): self
    {
        return new self($message, Status::PERMISSION_DENIED, $details);
    }

    /**
     * Create exception for unauthenticated
     */
    public static function unauthenticated(string $message, array $details = []): self
    {
        return new self($message, Status::UNAUTHENTICATED, $details);
    }

    /**
     * Create exception for unavailable
     */
    public static function unavailable(string $message, array $details = []): self
    {
        return new self($message, Status::UNAVAILABLE, $details);
    }

    /**
     * Create exception for deadline exceeded
     */
    public static function deadlineExceeded(string $message, array $details = []): self
    {
        return new self($message, Status::DEADLINE_EXCEEDED, $details);
    }

    /**
     * Create exception for resource exhausted
     */
    public static function resourceExhausted(string $message, array $details = []): self
    {
        return new self($message, Status::RESOURCE_EXHAUSTED, $details);
    }
}
