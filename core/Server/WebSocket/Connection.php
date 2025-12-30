<?php

declare(strict_types=1);

namespace HybridPHP\Core\Server\WebSocket;

use Workerman\Connection\TcpConnection;

/**
 * WebSocket Connection Wrapper
 * 
 * Wraps Workerman's TcpConnection with enhanced features for
 * room management, heartbeat tracking, and metadata storage.
 */
class Connection implements ConnectionInterface
{
    protected string $id;
    protected TcpConnection $connection;
    protected array $metadata = [];
    protected array $rooms = [];
    protected int $lastActivity;
    protected int $connectedAt;
    protected bool $alive = true;

    public function __construct(TcpConnection $connection, ?string $id = null)
    {
        $this->connection = $connection;
        $this->id = $id ?? $this->generateId();
        $this->connectedAt = time();
        $this->lastActivity = time();
        
        // Store reference on the underlying connection
        $connection->wsConnection = $this;
    }

    /**
     * Generate a unique connection ID
     */
    protected function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function send(mixed $data): bool
    {
        if (!$this->alive) {
            return false;
        }

        try {
            $message = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE);
            $this->connection->send($message);
            $this->updateActivity();
            return true;
        } catch (\Throwable $e) {
            $this->alive = false;
            return false;
        }
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        $this->alive = false;
        $this->connection->close();
    }

    public function isAlive(): bool
    {
        return $this->alive && $this->connection->getStatus() === TcpConnection::STATUS_ESTABLISHED;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setMetadata(string $key, mixed $value): void
    {
        $this->metadata[$key] = $value;
    }

    public function getRooms(): array
    {
        return $this->rooms;
    }

    /**
     * Add connection to a room (internal use)
     */
    public function addRoom(string $room): void
    {
        if (!in_array($room, $this->rooms, true)) {
            $this->rooms[] = $room;
        }
    }

    /**
     * Remove connection from a room (internal use)
     */
    public function removeRoom(string $room): void
    {
        $this->rooms = array_values(array_filter(
            $this->rooms,
            fn($r) => $r !== $room
        ));
    }

    public function getLastActivity(): int
    {
        return $this->lastActivity;
    }

    public function updateActivity(): void
    {
        $this->lastActivity = time();
    }

    public function getConnectedAt(): int
    {
        return $this->connectedAt;
    }

    /**
     * Get the underlying Workerman connection
     */
    public function getRawConnection(): TcpConnection
    {
        return $this->connection;
    }

    /**
     * Mark connection as dead
     */
    public function markDead(): void
    {
        $this->alive = false;
    }
}
