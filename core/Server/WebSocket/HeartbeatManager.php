<?php

declare(strict_types=1);

namespace HybridPHP\Core\Server\WebSocket;

use Workerman\Timer;

/**
 * WebSocket Heartbeat Manager
 * 
 * Manages heartbeat detection for WebSocket connections to detect
 * and clean up dead connections.
 */
class HeartbeatManager
{
    /**
     * Heartbeat interval in seconds
     */
    protected int $interval;

    /**
     * Connection timeout in seconds (no activity)
     */
    protected int $timeout;

    /**
     * Timer ID for heartbeat check
     */
    protected ?int $timerId = null;

    /**
     * Callback for dead connection handling
     * @var callable|null
     */
    protected $onDeadConnection = null;

    /**
     * Callback for ping sending
     * @var callable|null
     */
    protected $onPing = null;

    /**
     * Active connections being monitored
     * @var array<string, ConnectionInterface>
     */
    protected array $connections = [];

    /**
     * Ping message to send
     */
    protected string $pingMessage;

    /**
     * Expected pong message
     */
    protected string $pongMessage;

    /**
     * Statistics
     */
    protected array $stats = [
        'pings_sent' => 0,
        'pongs_received' => 0,
        'dead_connections' => 0,
        'checks_performed' => 0,
    ];

    public function __construct(
        int $interval = 30,
        int $timeout = 60,
        string $pingMessage = '{"type":"ping"}',
        string $pongMessage = 'pong'
    ) {
        $this->interval = $interval;
        $this->timeout = $timeout;
        $this->pingMessage = $pingMessage;
        $this->pongMessage = $pongMessage;
    }

    /**
     * Start heartbeat monitoring
     */
    public function start(): void
    {
        if ($this->timerId !== null) {
            return;
        }

        $this->timerId = Timer::add($this->interval, function () {
            $this->check();
        });
    }

    /**
     * Stop heartbeat monitoring
     */
    public function stop(): void
    {
        if ($this->timerId !== null) {
            Timer::del($this->timerId);
            $this->timerId = null;
        }
    }

    /**
     * Add a connection to monitor
     */
    public function addConnection(ConnectionInterface $connection): void
    {
        $this->connections[$connection->getId()] = $connection;
    }

    /**
     * Remove a connection from monitoring
     */
    public function removeConnection(ConnectionInterface $connection): void
    {
        unset($this->connections[$connection->getId()]);
    }

    /**
     * Remove connection by ID
     */
    public function removeConnectionById(string $id): void
    {
        unset($this->connections[$id]);
    }

    /**
     * Handle pong response from client
     */
    public function handlePong(ConnectionInterface $connection): void
    {
        $connection->updateActivity();
        $this->stats['pongs_received']++;
    }

    /**
     * Check if message is a pong response
     */
    public function isPong(string $message): bool
    {
        $decoded = json_decode($message, true);
        if (is_array($decoded) && isset($decoded['type']) && $decoded['type'] === 'pong') {
            return true;
        }
        return $message === $this->pongMessage;
    }

    /**
     * Perform heartbeat check on all connections
     */
    public function check(): void
    {
        $this->stats['checks_performed']++;
        $now = time();
        $deadConnections = [];

        foreach ($this->connections as $id => $connection) {
            $lastActivity = $connection->getLastActivity();
            $elapsed = $now - $lastActivity;

            if ($elapsed >= $this->timeout) {
                // Connection timed out
                $deadConnections[] = $connection;
                $this->stats['dead_connections']++;
            } elseif ($elapsed >= $this->interval) {
                // Send ping
                $this->sendPing($connection);
            }
        }

        // Handle dead connections
        foreach ($deadConnections as $connection) {
            $this->handleDeadConnection($connection);
        }
    }

    /**
     * Send ping to a connection
     */
    protected function sendPing(ConnectionInterface $connection): void
    {
        $connection->send($this->pingMessage);
        $this->stats['pings_sent']++;

        if ($this->onPing !== null) {
            ($this->onPing)($connection);
        }
    }

    /**
     * Handle a dead connection
     */
    protected function handleDeadConnection(ConnectionInterface $connection): void
    {
        // Remove from monitoring
        unset($this->connections[$connection->getId()]);

        // Mark as dead
        if ($connection instanceof Connection) {
            $connection->markDead();
        }

        // Call callback if set
        if ($this->onDeadConnection !== null) {
            ($this->onDeadConnection)($connection);
        }

        // Close the connection
        $connection->close(1001, 'Connection timeout');
    }

    /**
     * Set callback for dead connection handling
     */
    public function onDeadConnection(callable $callback): void
    {
        $this->onDeadConnection = $callback;
    }

    /**
     * Set callback for ping sending
     */
    public function onPing(callable $callback): void
    {
        $this->onPing = $callback;
    }

    /**
     * Get heartbeat statistics
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'active_connections' => count($this->connections),
            'interval' => $this->interval,
            'timeout' => $this->timeout,
            'running' => $this->timerId !== null,
        ]);
    }

    /**
     * Reset statistics
     */
    public function resetStats(): void
    {
        $this->stats = [
            'pings_sent' => 0,
            'pongs_received' => 0,
            'dead_connections' => 0,
            'checks_performed' => 0,
        ];
    }

    /**
     * Get all monitored connections
     * 
     * @return ConnectionInterface[]
     */
    public function getConnections(): array
    {
        return $this->connections;
    }

    /**
     * Set heartbeat interval
     */
    public function setInterval(int $interval): void
    {
        $this->interval = $interval;
        
        // Restart timer if running
        if ($this->timerId !== null) {
            $this->stop();
            $this->start();
        }
    }

    /**
     * Set connection timeout
     */
    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
    }

    /**
     * Check if heartbeat is running
     */
    public function isRunning(): bool
    {
        return $this->timerId !== null;
    }
}
