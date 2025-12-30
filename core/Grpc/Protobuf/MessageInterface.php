<?php

declare(strict_types=1);

namespace HybridPHP\Core\Grpc\Protobuf;

/**
 * Interface for Protocol Buffer messages
 */
interface MessageInterface
{
    /**
     * Serialize message to binary format
     */
    public function serializeToString(): string;

    /**
     * Parse message from binary format
     */
    public function mergeFromString(string $data): void;

    /**
     * Serialize message to JSON
     */
    public function serializeToJsonString(): string;

    /**
     * Parse message from JSON
     */
    public function mergeFromJsonString(string $data): void;

    /**
     * Clear all fields
     */
    public function clear(): void;

    /**
     * Get message descriptor name
     */
    public static function getDescriptor(): string;
}
