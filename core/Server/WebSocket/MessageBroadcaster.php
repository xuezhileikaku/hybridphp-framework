<?php

declare(strict_types=1);

namespace HybridPHP\Core\Server\WebSocket;

use Amp\Future;
use function Amp\async;
use function Amp\Future\await;

/**
 * Optimized WebSocket Message Broadcaster
 * 
 * Provides efficient message broadcasting with batching,
 * filtering, and async delivery capabilities.
 */
class MessageBroadcaster
{
    protected RoomManager $roomManager;
    
    /**
     * Message queue for batch processing
     * @var array<array{room: string, message: mixed, exclude: array}>
     */
    protected array $messageQueue = [];

    /**
     * Batch size for message processing
     */
    protected int $batchSize = 100;

    /**
     * Statistics tracking
     */
    protected array $stats = [
        'messages_sent' => 0,
        'messages_failed' => 0,
        'broadcasts' => 0,
    ];

    public function __construct(RoomManager $roomManager, int $batchSize = 100)
    {
        $this->roomManager = $roomManager;
        $this->batchSize = $batchSize;
    }

    /**
     * Broadcast message to all connections in a room
     * 
     * @param string $room Room name
     * @param mixed $message Message to send
     * @param array $exclude Connection IDs to exclude
     * @return int Number of messages sent
     */
    public function toRoom(string $room, mixed $message, array $exclude = []): int
    {
        $connections = $this->roomManager->getConnections($room);
        return $this->sendToConnections($connections, $message, $exclude);
    }

    /**
     * Broadcast message to multiple rooms
     * 
     * @param array $rooms Room names
     * @param mixed $message Message to send
     * @param array $exclude Connection IDs to exclude
     * @return int Number of messages sent
     */
    public function toRooms(array $rooms, mixed $message, array $exclude = []): int
    {
        $sent = 0;
        $sentIds = []; // Track sent connection IDs to avoid duplicates

        foreach ($rooms as $room) {
            $connections = $this->roomManager->getConnections($room);
            foreach ($connections as $connection) {
                $id = $connection->getId();
                if (!in_array($id, $exclude, true) && !in_array($id, $sentIds, true)) {
                    if ($connection->send($message)) {
                        $sent++;
                        $this->stats['messages_sent']++;
                    } else {
                        $this->stats['messages_failed']++;
                    }
                    $sentIds[] = $id;
                }
            }
        }

        $this->stats['broadcasts']++;
        return $sent;
    }

    /**
     * Broadcast message to all connections except specified ones
     * 
     * @param array<ConnectionInterface> $connections All connections
     * @param mixed $message Message to send
     * @param array $exclude Connection IDs to exclude
     * @return int Number of messages sent
     */
    public function toAll(array $connections, mixed $message, array $exclude = []): int
    {
        return $this->sendToConnections($connections, $message, $exclude);
    }

    /**
     * Send message to a specific connection
     */
    public function toConnection(ConnectionInterface $connection, mixed $message): bool
    {
        $result = $connection->send($message);
        if ($result) {
            $this->stats['messages_sent']++;
        } else {
            $this->stats['messages_failed']++;
        }
        return $result;
    }

    /**
     * Broadcast with filter callback
     * 
     * @param array<ConnectionInterface> $connections Connections to filter
     * @param mixed $message Message to send
     * @param callable $filter Filter function (ConnectionInterface) => bool
     * @return int Number of messages sent
     */
    public function toFiltered(array $connections, mixed $message, callable $filter): int
    {
        $sent = 0;
        foreach ($connections as $connection) {
            if ($filter($connection)) {
                if ($connection->send($message)) {
                    $sent++;
                    $this->stats['messages_sent']++;
                } else {
                    $this->stats['messages_failed']++;
                }
            }
        }
        $this->stats['broadcasts']++;
        return $sent;
    }

    /**
     * Async broadcast to room
     */
    public function toRoomAsync(string $room, mixed $message, array $exclude = []): Future
    {
        return async(function () use ($room, $message, $exclude) {
            return $this->toRoom($room, $message, $exclude);
        });
    }

    /**
     * Async broadcast to multiple rooms
     */
    public function toRoomsAsync(array $rooms, mixed $message, array $exclude = []): Future
    {
        return async(function () use ($rooms, $message, $exclude) {
            return $this->toRooms($rooms, $message, $exclude);
        });
    }

    /**
     * Queue a message for batch processing
     */
    public function queue(string $room, mixed $message, array $exclude = []): void
    {
        $this->messageQueue[] = [
            'room' => $room,
            'message' => $message,
            'exclude' => $exclude,
        ];

        // Auto-flush if batch size reached
        if (count($this->messageQueue) >= $this->batchSize) {
            $this->flush();
        }
    }

    /**
     * Flush queued messages
     * 
     * @return int Total messages sent
     */
    public function flush(): int
    {
        $totalSent = 0;
        
        foreach ($this->messageQueue as $item) {
            $totalSent += $this->toRoom($item['room'], $item['message'], $item['exclude']);
        }
        
        $this->messageQueue = [];
        return $totalSent;
    }

    /**
     * Async flush queued messages
     */
    public function flushAsync(): Future
    {
        return async(function () {
            return $this->flush();
        });
    }

    /**
     * Send to connections helper
     */
    protected function sendToConnections(array $connections, mixed $message, array $exclude): int
    {
        $sent = 0;
        foreach ($connections as $connection) {
            if (!in_array($connection->getId(), $exclude, true)) {
                if ($connection->send($message)) {
                    $sent++;
                    $this->stats['messages_sent']++;
                } else {
                    $this->stats['messages_failed']++;
                }
            }
        }
        $this->stats['broadcasts']++;
        return $sent;
    }

    /**
     * Get broadcasting statistics
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'queued_messages' => count($this->messageQueue),
        ]);
    }

    /**
     * Reset statistics
     */
    public function resetStats(): void
    {
        $this->stats = [
            'messages_sent' => 0,
            'messages_failed' => 0,
            'broadcasts' => 0,
        ];
    }
}
