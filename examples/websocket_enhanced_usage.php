<?php

/**
 * Enhanced WebSocket Server Usage Example
 * 
 * Demonstrates the enhanced WebSocket features:
 * - Room/Channel support
 * - Message broadcasting
 * - Heartbeat detection
 * - Reconnection mechanism
 */

require_once __DIR__ . '/../vendor/autoload.php';

use HybridPHP\Core\Server\WebSocket\EnhancedWebSocketServer;
use HybridPHP\Core\Server\WebSocket\Connection;
use HybridPHP\Core\EventEmitter;

// Create event emitter for handling WebSocket events
$eventEmitter = new EventEmitter();

// Create enhanced WebSocket server
$server = new EnhancedWebSocketServer([
    'host' => '0.0.0.0',
    'port' => 9090,
    'processes' => 2,
    'heartbeat_interval' => 30,
    'heartbeat_timeout' => 60,
    'reconnection_ttl' => 300,
    'reconnection_max_attempts' => 5,
    'max_connections_per_room' => 100,
    'max_rooms_per_connection' => 10,
]);

$server->setEventEmitter($eventEmitter);

// Register custom message handlers
$server->on('chat', function (Connection $connection, array $message, EnhancedWebSocketServer $server) {
    $room = $message['room'] ?? 'general';
    $text = $message['text'] ?? '';
    
    // Broadcast chat message to room
    $server->broadcast($room, [
        'type' => 'chat',
        'from' => $connection->getId(),
        'room' => $room,
        'text' => $text,
        'timestamp' => time(),
    ]);
    
    return ['type' => 'chat_sent', 'room' => $room];
});

$server->on('private', function (Connection $connection, array $message, EnhancedWebSocketServer $server) {
    $targetId = $message['to'] ?? null;
    $text = $message['text'] ?? '';
    
    if (!$targetId) {
        return ['type' => 'error', 'message' => 'Target connection ID required'];
    }
    
    $sent = $server->sendTo($targetId, [
        'type' => 'private',
        'from' => $connection->getId(),
        'text' => $text,
        'timestamp' => time(),
    ]);
    
    return ['type' => 'private_sent', 'delivered' => $sent];
});

$server->on('room_info', function (Connection $connection, array $message, EnhancedWebSocketServer $server) {
    $room = $message['room'] ?? null;
    
    if (!$room) {
        return ['type' => 'error', 'message' => 'Room name required'];
    }
    
    $roomManager = $server->getRoomManager();
    $stats = $roomManager->getRoomStats($room);
    
    if (!$stats) {
        return ['type' => 'error', 'message' => 'Room not found'];
    }
    
    return [
        'type' => 'room_info',
        'room' => $room,
        'members' => $stats['connections'],
        'created_at' => $stats['created_at'],
    ];
});

$server->on('list_rooms', function (Connection $connection, array $message, EnhancedWebSocketServer $server) {
    $roomManager = $server->getRoomManager();
    $rooms = [];
    
    foreach ($roomManager->getRooms() as $room) {
        $rooms[] = [
            'name' => $room,
            'members' => $roomManager->getRoomSize($room),
        ];
    }
    
    return ['type' => 'rooms_list', 'rooms' => $rooms];
});

$server->on('stats', function (Connection $connection, array $message, EnhancedWebSocketServer $server) {
    return [
        'type' => 'server_stats',
        'stats' => $server->getStats(),
    ];
});

// Event listeners
$eventEmitter->on('websocket.started', function ($server) {
    echo "🚀 Enhanced WebSocket Server Started!\n";
    echo "📡 Listening on ws://0.0.0.0:9090\n";
    echo "💡 Features: Rooms, Heartbeat, Reconnection\n";
});

$eventEmitter->on('websocket.connect', function (Connection $connection) {
    echo "✅ New connection: {$connection->getId()}\n";
});

$eventEmitter->on('websocket.disconnect', function (Connection $connection, string $reason, string $token) {
    echo "❌ Disconnected: {$connection->getId()} (reason: {$reason})\n";
    echo "   Reconnection token: {$token}\n";
});

$eventEmitter->on('websocket.room.join', function (Connection $connection, string $room) {
    echo "🚪 {$connection->getId()} joined room: {$room}\n";
});

$eventEmitter->on('websocket.room.leave', function (Connection $connection, string $room) {
    echo "🚶 {$connection->getId()} left room: {$room}\n";
});

$eventEmitter->on('websocket.reconnect', function (Connection $connection, array $session) {
    echo "🔄 Reconnected: {$connection->getId()} (previous: {$session['previous_connection_id']})\n";
});

// Start the server
echo "\n=== Enhanced WebSocket Server Example ===\n\n";
echo "Available message types:\n";
echo "  - join: {\"type\":\"join\",\"room\":\"room_name\"}\n";
echo "  - leave: {\"type\":\"leave\",\"room\":\"room_name\"}\n";
echo "  - broadcast: {\"type\":\"broadcast\",\"room\":\"room_name\",\"data\":\"message\"}\n";
echo "  - chat: {\"type\":\"chat\",\"room\":\"room_name\",\"text\":\"hello\"}\n";
echo "  - private: {\"type\":\"private\",\"to\":\"connection_id\",\"text\":\"hello\"}\n";
echo "  - room_info: {\"type\":\"room_info\",\"room\":\"room_name\"}\n";
echo "  - list_rooms: {\"type\":\"list_rooms\"}\n";
echo "  - stats: {\"type\":\"stats\"}\n";
echo "  - reconnect: {\"type\":\"reconnect\",\"token\":\"your_token\"}\n";
echo "  - ping: {\"type\":\"ping\"}\n";
echo "\n";

// Note: In production, use ServerManager to start the server
// For this example, we'll use Workerman directly
\Workerman\Worker::runAll();
