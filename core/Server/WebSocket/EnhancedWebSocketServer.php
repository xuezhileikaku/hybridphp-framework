<?php

declare(strict_types=1);

namespace HybridPHP\Core\Server\WebSocket;

use HybridPHP\Core\Server\AbstractServer;
use HybridPHP\Core\EventEmitter;
use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Psr\Log\LoggerInterface;

/**
 * Enhanced WebSocket Server
 * 
 * Full-featured WebSocket server with room/channel support,
 * optimized broadcasting, heartbeat detection, and reconnection.
 */
class EnhancedWebSocketServer extends AbstractServer
{
    protected Worker $worker;
    protected RoomManager $roomManager;
    protected MessageBroadcaster $broadcaster;
    protected HeartbeatManager $heartbeat;
    protected ReconnectionManager $reconnection;
    protected ?EventEmitter $eventEmitter;
    protected ?LoggerInterface $logger;

    /**
     * All active connections
     * @var array<string, Connection>
     */
    protected array $connections = [];

    /**
     * Message handlers
     * @var array<string, callable>
     */
    protected array $handlers = [];

    /**
     * Server configuration
     */
    protected array $config;

    /**
     * Statistics
     */
    protected array $stats = [
        'total_connections' => 0,
        'total_messages' => 0,
        'started_at' => 0,
    ];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'host' => '0.0.0.0',
            'port' => 2346,
            'processes' => 1,
            'heartbeat_interval' => 30,
            'heartbeat_timeout' => 60,
            'reconnection_ttl' => 300,
            'reconnection_max_attempts' => 5,
            'max_connections_per_room' => 0,
            'max_rooms_per_connection' => 0,
            'ssl' => null,
        ], $config);

        $this->initializeComponents();
        $this->initializeWorker();
    }

    /**
     * Initialize all components
     */
    protected function initializeComponents(): void
    {
        $this->roomManager = new RoomManager(
            $this->config['max_connections_per_room'],
            $this->config['max_rooms_per_connection']
        );

        $this->broadcaster = new MessageBroadcaster($this->roomManager);

        $this->heartbeat = new HeartbeatManager(
            $this->config['heartbeat_interval'],
            $this->config['heartbeat_timeout']
        );

        $this->reconnection = new ReconnectionManager(
            $this->config['reconnection_ttl'],
            $this->config['reconnection_max_attempts']
        );

        // Set up heartbeat dead connection handler
        $this->heartbeat->onDeadConnection(function (ConnectionInterface $connection) {
            $this->handleDisconnect($connection, 'heartbeat_timeout');
        });
    }

    /**
     * Initialize Workerman worker
     */
    protected function initializeWorker(): void
    {
        $listen = sprintf(
            'websocket://%s:%d',
            $this->config['host'],
            $this->config['port']
        );

        $context = [];
        if ($this->config['ssl']) {
            $context['ssl'] = $this->config['ssl'];
        }

        $this->worker = new Worker($listen, $context);
        $this->worker->count = $this->config['processes'];
        $this->worker->name = 'HybridPHP-WebSocket';

        // Set up callbacks
        $this->worker->onConnect = [$this, 'onConnect'];
        $this->worker->onMessage = [$this, 'onMessage'];
        $this->worker->onClose = [$this, 'onClose'];
        $this->worker->onError = [$this, 'onError'];
        
        $this->worker->onWorkerStart = function () {
            $this->stats['started_at'] = time();
            $this->heartbeat->start();
            $this->reconnection->startCleanup();
            
            if ($this->eventEmitter) {
                $this->eventEmitter->emit('websocket.started', [$this]);
            }
        };

        $this->worker->onWorkerStop = function () {
            $this->heartbeat->stop();
            $this->reconnection->stopCleanup();
            
            if ($this->eventEmitter) {
                $this->eventEmitter->emit('websocket.stopped', [$this]);
            }
        };
    }

    /**
     * Start listening (called by ServerManager)
     */
    public function listen(): void
    {
        // Worker is started by ServerManager via Worker::runAll()
    }

    /**
     * Handle new connection
     */
    public function onConnect(TcpConnection $tcpConnection): void
    {
        $connection = new Connection($tcpConnection);
        $this->connections[$connection->getId()] = $connection;
        $this->heartbeat->addConnection($connection);
        $this->stats['total_connections']++;

        // Send connection info to client
        $connection->send([
            'type' => 'connected',
            'connection_id' => $connection->getId(),
            'timestamp' => time(),
        ]);

        if ($this->eventEmitter) {
            $this->eventEmitter->emit('websocket.connect', [$connection]);
        }

        $this->log('info', 'WebSocket connection established', [
            'connection_id' => $connection->getId(),
        ]);
    }

    /**
     * Handle incoming message
     */
    public function onMessage(TcpConnection $tcpConnection, string $data): void
    {
        $connection = $this->getConnectionFromTcp($tcpConnection);
        if (!$connection) {
            return;
        }

        $connection->updateActivity();
        $this->stats['total_messages']++;

        // Check for pong response
        if ($this->heartbeat->isPong($data)) {
            $this->heartbeat->handlePong($connection);
            return;
        }

        // Parse message
        $message = json_decode($data, true);
        if (!is_array($message)) {
            $message = ['type' => 'raw', 'data' => $data];
        }

        $type = $message['type'] ?? 'message';

        // Handle built-in message types
        switch ($type) {
            case 'join':
                $this->handleJoin($connection, $message);
                break;
            case 'leave':
                $this->handleLeave($connection, $message);
                break;
            case 'broadcast':
                $this->handleBroadcast($connection, $message);
                break;
            case 'reconnect':
                $this->handleReconnect($connection, $message);
                break;
            case 'ping':
                $connection->send(['type' => 'pong', 'timestamp' => time()]);
                break;
            default:
                $this->handleCustomMessage($connection, $type, $message);
        }

        if ($this->eventEmitter) {
            $this->eventEmitter->emit('websocket.message', [$connection, $message]);
        }
    }

    /**
     * Handle connection close
     */
    public function onClose(TcpConnection $tcpConnection): void
    {
        $connection = $this->getConnectionFromTcp($tcpConnection);
        if (!$connection) {
            return;
        }

        $this->handleDisconnect($connection, 'client_close');
    }

    /**
     * Handle connection error
     */
    public function onError(TcpConnection $tcpConnection, int $code, string $message): void
    {
        $connection = $this->getConnectionFromTcp($tcpConnection);
        
        $this->log('error', 'WebSocket error', [
            'connection_id' => $connection?->getId(),
            'code' => $code,
            'message' => $message,
        ]);

        if ($this->eventEmitter) {
            $this->eventEmitter->emit('websocket.error', [$connection, $code, $message]);
        }
    }

    /**
     * Handle join room request
     */
    protected function handleJoin(Connection $connection, array $message): void
    {
        $room = $message['room'] ?? null;
        if (!$room) {
            $connection->send(['type' => 'error', 'message' => 'Room name required']);
            return;
        }

        if ($this->roomManager->join($connection, $room)) {
            $connection->send([
                'type' => 'joined',
                'room' => $room,
                'members' => $this->roomManager->getRoomSize($room),
            ]);

            // Notify room members
            $this->broadcaster->toRoom($room, [
                'type' => 'member_joined',
                'room' => $room,
                'connection_id' => $connection->getId(),
            ], [$connection->getId()]);

            if ($this->eventEmitter) {
                $this->eventEmitter->emit('websocket.room.join', [$connection, $room]);
            }
        } else {
            $connection->send(['type' => 'error', 'message' => 'Failed to join room']);
        }
    }

    /**
     * Handle leave room request
     */
    protected function handleLeave(Connection $connection, array $message): void
    {
        $room = $message['room'] ?? null;
        if (!$room) {
            $connection->send(['type' => 'error', 'message' => 'Room name required']);
            return;
        }

        if ($this->roomManager->leave($connection, $room)) {
            $connection->send(['type' => 'left', 'room' => $room]);

            // Notify room members
            $this->broadcaster->toRoom($room, [
                'type' => 'member_left',
                'room' => $room,
                'connection_id' => $connection->getId(),
            ]);

            if ($this->eventEmitter) {
                $this->eventEmitter->emit('websocket.room.leave', [$connection, $room]);
            }
        }
    }

    /**
     * Handle broadcast request
     */
    protected function handleBroadcast(Connection $connection, array $message): void
    {
        $room = $message['room'] ?? null;
        $data = $message['data'] ?? null;

        if (!$room || !$data) {
            $connection->send(['type' => 'error', 'message' => 'Room and data required']);
            return;
        }

        // Check if connection is in the room
        if (!$this->roomManager->isInRoom($connection, $room)) {
            $connection->send(['type' => 'error', 'message' => 'Not in room']);
            return;
        }

        $sent = $this->broadcaster->toRoom($room, [
            'type' => 'broadcast',
            'room' => $room,
            'from' => $connection->getId(),
            'data' => $data,
            'timestamp' => time(),
        ], [$connection->getId()]);

        $connection->send(['type' => 'broadcast_sent', 'recipients' => $sent]);
    }

    /**
     * Handle reconnection request
     */
    protected function handleReconnect(Connection $connection, array $message): void
    {
        $token = $message['token'] ?? null;
        if (!$token) {
            $connection->send(['type' => 'error', 'message' => 'Reconnection token required']);
            return;
        }

        $session = $this->reconnection->reconnect($token, $connection);
        if ($session) {
            // Rejoin rooms
            foreach ($session['rooms'] as $room) {
                $this->roomManager->join($connection, $room);
            }

            $connection->send([
                'type' => 'reconnected',
                'rooms' => $session['rooms'],
                'previous_connection_id' => $session['previous_connection_id'],
            ]);

            if ($this->eventEmitter) {
                $this->eventEmitter->emit('websocket.reconnect', [$connection, $session]);
            }
        } else {
            $connection->send(['type' => 'error', 'message' => 'Invalid or expired token']);
        }
    }

    /**
     * Handle custom message types
     */
    protected function handleCustomMessage(Connection $connection, string $type, array $message): void
    {
        if (isset($this->handlers[$type])) {
            $response = ($this->handlers[$type])($connection, $message, $this);
            if ($response !== null) {
                $connection->send($response);
            }
        } else {
            // Default echo behavior
            $connection->send([
                'type' => 'response',
                'original_type' => $type,
                'data' => $message,
            ]);
        }
    }

    /**
     * Handle disconnection
     */
    protected function handleDisconnect(ConnectionInterface $connection, string $reason): void
    {
        $connectionId = $connection->getId();

        // Create reconnection token before cleanup
        $token = $this->reconnection->createSession($connection);

        // Remove from all rooms
        $this->roomManager->leaveAll($connection);

        // Remove from heartbeat monitoring
        $this->heartbeat->removeConnection($connection);

        // Remove from connections
        unset($this->connections[$connectionId]);

        if ($this->eventEmitter) {
            $this->eventEmitter->emit('websocket.disconnect', [$connection, $reason, $token]);
        }

        $this->log('info', 'WebSocket connection closed', [
            'connection_id' => $connectionId,
            'reason' => $reason,
        ]);
    }

    /**
     * Get Connection from TcpConnection
     */
    protected function getConnectionFromTcp(TcpConnection $tcpConnection): ?Connection
    {
        return $tcpConnection->wsConnection ?? null;
    }

    /**
     * Register a message handler
     */
    public function on(string $type, callable $handler): self
    {
        $this->handlers[$type] = $handler;
        return $this;
    }

    /**
     * Set event emitter
     */
    public function setEventEmitter(EventEmitter $emitter): self
    {
        $this->eventEmitter = $emitter;
        return $this;
    }

    /**
     * Set logger
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * Log a message
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->log($level, '[WebSocket] ' . $message, $context);
        }
    }

    // Public API methods

    /**
     * Broadcast to a room
     */
    public function broadcast(string $room, mixed $message, array $exclude = []): int
    {
        return $this->broadcaster->toRoom($room, $message, $exclude);
    }

    /**
     * Broadcast to all connections
     */
    public function broadcastAll(mixed $message, array $exclude = []): int
    {
        return $this->broadcaster->toAll($this->connections, $message, $exclude);
    }

    /**
     * Send to a specific connection
     */
    public function sendTo(string $connectionId, mixed $message): bool
    {
        $connection = $this->connections[$connectionId] ?? null;
        return $connection ? $connection->send($message) : false;
    }

    /**
     * Join a connection to a room
     */
    public function joinRoom(string $connectionId, string $room): bool
    {
        $connection = $this->connections[$connectionId] ?? null;
        return $connection ? $this->roomManager->join($connection, $room) : false;
    }

    /**
     * Remove a connection from a room
     */
    public function leaveRoom(string $connectionId, string $room): bool
    {
        $connection = $this->connections[$connectionId] ?? null;
        return $connection ? $this->roomManager->leave($connection, $room) : false;
    }

    /**
     * Get room manager
     */
    public function getRoomManager(): RoomManager
    {
        return $this->roomManager;
    }

    /**
     * Get broadcaster
     */
    public function getBroadcaster(): MessageBroadcaster
    {
        return $this->broadcaster;
    }

    /**
     * Get heartbeat manager
     */
    public function getHeartbeat(): HeartbeatManager
    {
        return $this->heartbeat;
    }

    /**
     * Get reconnection manager
     */
    public function getReconnection(): ReconnectionManager
    {
        return $this->reconnection;
    }

    /**
     * Get all connections
     */
    public function getConnections(): array
    {
        return $this->connections;
    }

    /**
     * Get connection by ID
     */
    public function getConnection(string $id): ?Connection
    {
        return $this->connections[$id] ?? null;
    }

    /**
     * Get connection count
     */
    public function getConnectionCount(): int
    {
        return count($this->connections);
    }

    /**
     * Get server statistics
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'active_connections' => count($this->connections),
            'rooms' => $this->roomManager->getAllStats(),
            'heartbeat' => $this->heartbeat->getStats(),
            'reconnection' => $this->reconnection->getStats(),
            'broadcaster' => $this->broadcaster->getStats(),
            'uptime' => $this->stats['started_at'] > 0 ? time() - $this->stats['started_at'] : 0,
        ]);
    }

    /**
     * Get the underlying worker
     */
    public function getWorker(): Worker
    {
        return $this->worker;
    }

    /**
     * Stop the server
     */
    public function stop(): void
    {
        $this->heartbeat->stop();
        $this->reconnection->stopCleanup();

        // Close all connections
        foreach ($this->connections as $connection) {
            $connection->close(1001, 'Server shutdown');
        }

        $this->connections = [];
    }
}
