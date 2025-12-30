<?php

declare(strict_types=1);

namespace HybridPHP\Core\Server\WebSocket;

/**
 * WebSocket Room/Channel Manager
 * 
 * Manages rooms (channels) for WebSocket connections, enabling
 * pub/sub patterns and targeted message broadcasting.
 */
class RoomManager
{
    /**
     * Room to connections mapping
     * @var array<string, array<string, ConnectionInterface>>
     */
    protected array $rooms = [];

    /**
     * Room metadata storage
     * @var array<string, array>
     */
    protected array $roomMetadata = [];

    /**
     * Maximum connections per room (0 = unlimited)
     */
    protected int $maxConnectionsPerRoom = 0;

    /**
     * Maximum rooms per connection (0 = unlimited)
     */
    protected int $maxRoomsPerConnection = 0;

    public function __construct(int $maxConnectionsPerRoom = 0, int $maxRoomsPerConnection = 0)
    {
        $this->maxConnectionsPerRoom = $maxConnectionsPerRoom;
        $this->maxRoomsPerConnection = $maxRoomsPerConnection;
    }

    /**
     * Join a connection to a room
     */
    public function join(ConnectionInterface $connection, string $room): bool
    {
        $connectionId = $connection->getId();

        // Check room capacity
        if ($this->maxConnectionsPerRoom > 0 && $this->getRoomSize($room) >= $this->maxConnectionsPerRoom) {
            return false;
        }

        // Check connection room limit
        if ($this->maxRoomsPerConnection > 0 && count($connection->getRooms()) >= $this->maxRoomsPerConnection) {
            return false;
        }

        // Initialize room if needed
        if (!isset($this->rooms[$room])) {
            $this->rooms[$room] = [];
            $this->roomMetadata[$room] = [
                'created_at' => time(),
                'metadata' => [],
            ];
        }

        // Add connection to room
        $this->rooms[$room][$connectionId] = $connection;

        // Update connection's room list
        if ($connection instanceof Connection) {
            $connection->addRoom($room);
        }

        return true;
    }

    /**
     * Remove a connection from a room
     */
    public function leave(ConnectionInterface $connection, string $room): bool
    {
        $connectionId = $connection->getId();

        if (!isset($this->rooms[$room][$connectionId])) {
            return false;
        }

        unset($this->rooms[$room][$connectionId]);

        // Update connection's room list
        if ($connection instanceof Connection) {
            $connection->removeRoom($room);
        }

        // Clean up empty rooms
        if (empty($this->rooms[$room])) {
            unset($this->rooms[$room]);
            unset($this->roomMetadata[$room]);
        }

        return true;
    }

    /**
     * Remove a connection from all rooms
     */
    public function leaveAll(ConnectionInterface $connection): void
    {
        foreach ($connection->getRooms() as $room) {
            $this->leave($connection, $room);
        }
    }

    /**
     * Check if a connection is in a room
     */
    public function isInRoom(ConnectionInterface $connection, string $room): bool
    {
        return isset($this->rooms[$room][$connection->getId()]);
    }

    /**
     * Get all connections in a room
     * 
     * @return ConnectionInterface[]
     */
    public function getConnections(string $room): array
    {
        return $this->rooms[$room] ?? [];
    }

    /**
     * Get the number of connections in a room
     */
    public function getRoomSize(string $room): int
    {
        return count($this->rooms[$room] ?? []);
    }

    /**
     * Get all room names
     * 
     * @return string[]
     */
    public function getRooms(): array
    {
        return array_keys($this->rooms);
    }

    /**
     * Check if a room exists
     */
    public function roomExists(string $room): bool
    {
        return isset($this->rooms[$room]);
    }

    /**
     * Set room metadata
     */
    public function setRoomMetadata(string $room, string $key, mixed $value): void
    {
        if (isset($this->roomMetadata[$room])) {
            $this->roomMetadata[$room]['metadata'][$key] = $value;
        }
    }

    /**
     * Get room metadata
     */
    public function getRoomMetadata(string $room, ?string $key = null): mixed
    {
        if (!isset($this->roomMetadata[$room])) {
            return null;
        }

        if ($key === null) {
            return $this->roomMetadata[$room]['metadata'];
        }

        return $this->roomMetadata[$room]['metadata'][$key] ?? null;
    }

    /**
     * Get room statistics
     */
    public function getRoomStats(string $room): ?array
    {
        if (!isset($this->rooms[$room])) {
            return null;
        }

        return [
            'name' => $room,
            'connections' => $this->getRoomSize($room),
            'created_at' => $this->roomMetadata[$room]['created_at'] ?? 0,
            'metadata' => $this->roomMetadata[$room]['metadata'] ?? [],
        ];
    }

    /**
     * Get all rooms statistics
     */
    public function getAllStats(): array
    {
        $stats = [];
        foreach ($this->rooms as $room => $connections) {
            $stats[$room] = $this->getRoomStats($room);
        }
        return $stats;
    }

    /**
     * Delete a room and remove all connections
     */
    public function deleteRoom(string $room): bool
    {
        if (!isset($this->rooms[$room])) {
            return false;
        }

        // Remove all connections from the room
        foreach ($this->rooms[$room] as $connection) {
            if ($connection instanceof Connection) {
                $connection->removeRoom($room);
            }
        }

        unset($this->rooms[$room]);
        unset($this->roomMetadata[$room]);

        return true;
    }
}
