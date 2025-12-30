<?php

declare(strict_types=1);

namespace HybridPHP\Core\Server\WebSocket;

/**
 * WebSocket Connection Interface
 * 
 * Defines the contract for WebSocket connections with enhanced features
 * including room support, heartbeat, and reconnection capabilities.
 */
interface ConnectionInterface
{
    /**
     * Get the unique connection ID
     */
    public function getId(): string;

    /**
     * Send a message to this connection
     */
    public function send(mixed $data): bool;

    /**
     * Close the connection
     */
    public function close(int $code = 1000, string $reason = ''): void;

    /**
     * Check if connection is alive
     */
    public function isAlive(): bool;

    /**
     * Get connection metadata
     */
    public function getMetadata(): array;

    /**
     * Set connection metadata
     */
    public function setMetadata(string $key, mixed $value): void;

    /**
     * Get the rooms this connection belongs to
     */
    public function getRooms(): array;

    /**
     * Get last activity timestamp
     */
    public function getLastActivity(): int;

    /**
     * Update last activity timestamp
     */
    public function updateActivity(): void;

    /**
     * Get connection creation time
     */
    public function getConnectedAt(): int;
}
