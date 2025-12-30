<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use HybridPHP\Core\Server\WebSocket\RoomManager;
use HybridPHP\Core\Server\WebSocket\MessageBroadcaster;
use HybridPHP\Core\Server\WebSocket\HeartbeatManager;
use HybridPHP\Core\Server\WebSocket\ReconnectionManager;
use HybridPHP\Core\Server\WebSocket\ConnectionInterface;

/**
 * WebSocket Enhancement Tests
 * 
 * Tests for room management, broadcasting, heartbeat, and reconnection.
 */
class WebSocketTest extends TestCase
{
    /**
     * Create a mock connection for testing
     */
    protected function createMockConnection(string $id = null): ConnectionInterface
    {
        $id = $id ?? bin2hex(random_bytes(8));
        
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('getId')->willReturn($id);
        $connection->method('isAlive')->willReturn(true);
        $connection->method('getLastActivity')->willReturn(time());
        $connection->method('getConnectedAt')->willReturn(time());
        $connection->method('getMetadata')->willReturn([]);
        
        // Track rooms internally
        $rooms = [];
        $connection->method('getRooms')->willReturnCallback(function () use (&$rooms) {
            return $rooms;
        });
        
        return $connection;
    }

    // ==================== RoomManager Tests ====================

    public function testRoomManagerJoinRoom(): void
    {
        $roomManager = new RoomManager();
        $connection = $this->createMockConnection('conn1');

        $result = $roomManager->join($connection, 'test-room');

        $this->assertTrue($result);
        $this->assertTrue($roomManager->roomExists('test-room'));
        $this->assertEquals(1, $roomManager->getRoomSize('test-room'));
    }

    public function testRoomManagerLeaveRoom(): void
    {
        $roomManager = new RoomManager();
        $connection = $this->createMockConnection('conn1');

        $roomManager->join($connection, 'test-room');
        $result = $roomManager->leave($connection, 'test-room');

        $this->assertTrue($result);
        $this->assertFalse($roomManager->roomExists('test-room'));
    }

    public function testRoomManagerMultipleConnections(): void
    {
        $roomManager = new RoomManager();
        $conn1 = $this->createMockConnection('conn1');
        $conn2 = $this->createMockConnection('conn2');
        $conn3 = $this->createMockConnection('conn3');

        $roomManager->join($conn1, 'room1');
        $roomManager->join($conn2, 'room1');
        $roomManager->join($conn3, 'room1');

        $this->assertEquals(3, $roomManager->getRoomSize('room1'));
        $this->assertCount(3, $roomManager->getConnections('room1'));
    }

    public function testRoomManagerMaxConnectionsPerRoom(): void
    {
        $roomManager = new RoomManager(maxConnectionsPerRoom: 2);
        $conn1 = $this->createMockConnection('conn1');
        $conn2 = $this->createMockConnection('conn2');
        $conn3 = $this->createMockConnection('conn3');

        $this->assertTrue($roomManager->join($conn1, 'room1'));
        $this->assertTrue($roomManager->join($conn2, 'room1'));
        $this->assertFalse($roomManager->join($conn3, 'room1')); // Should fail

        $this->assertEquals(2, $roomManager->getRoomSize('room1'));
    }

    public function testRoomManagerIsInRoom(): void
    {
        $roomManager = new RoomManager();
        $conn1 = $this->createMockConnection('conn1');
        $conn2 = $this->createMockConnection('conn2');

        $roomManager->join($conn1, 'room1');

        $this->assertTrue($roomManager->isInRoom($conn1, 'room1'));
        $this->assertFalse($roomManager->isInRoom($conn2, 'room1'));
    }

    public function testRoomManagerMetadata(): void
    {
        $roomManager = new RoomManager();
        $connection = $this->createMockConnection('conn1');

        $roomManager->join($connection, 'room1');
        $roomManager->setRoomMetadata('room1', 'topic', 'General Discussion');

        $this->assertEquals('General Discussion', $roomManager->getRoomMetadata('room1', 'topic'));
    }

    public function testRoomManagerGetAllStats(): void
    {
        $roomManager = new RoomManager();
        $conn1 = $this->createMockConnection('conn1');
        $conn2 = $this->createMockConnection('conn2');

        $roomManager->join($conn1, 'room1');
        $roomManager->join($conn2, 'room2');

        $stats = $roomManager->getAllStats();

        $this->assertArrayHasKey('room1', $stats);
        $this->assertArrayHasKey('room2', $stats);
        $this->assertEquals(1, $stats['room1']['connections']);
        $this->assertEquals(1, $stats['room2']['connections']);
    }

    public function testRoomManagerDeleteRoom(): void
    {
        $roomManager = new RoomManager();
        $connection = $this->createMockConnection('conn1');

        $roomManager->join($connection, 'room1');
        $this->assertTrue($roomManager->roomExists('room1'));

        $roomManager->deleteRoom('room1');
        $this->assertFalse($roomManager->roomExists('room1'));
    }

    // ==================== MessageBroadcaster Tests ====================

    public function testBroadcasterToRoom(): void
    {
        $roomManager = new RoomManager();
        $broadcaster = new MessageBroadcaster($roomManager);

        // Create connections that track sent messages
        $sentMessages = [];
        
        $conn1 = $this->createMock(ConnectionInterface::class);
        $conn1->method('getId')->willReturn('conn1');
        $conn1->method('send')->willReturnCallback(function ($msg) use (&$sentMessages) {
            $sentMessages['conn1'][] = $msg;
            return true;
        });
        $conn1->method('getRooms')->willReturn([]);

        $conn2 = $this->createMock(ConnectionInterface::class);
        $conn2->method('getId')->willReturn('conn2');
        $conn2->method('send')->willReturnCallback(function ($msg) use (&$sentMessages) {
            $sentMessages['conn2'][] = $msg;
            return true;
        });
        $conn2->method('getRooms')->willReturn([]);

        $roomManager->join($conn1, 'room1');
        $roomManager->join($conn2, 'room1');

        $sent = $broadcaster->toRoom('room1', ['type' => 'test', 'data' => 'hello']);

        $this->assertEquals(2, $sent);
        $this->assertCount(1, $sentMessages['conn1']);
        $this->assertCount(1, $sentMessages['conn2']);
    }

    public function testBroadcasterWithExclusion(): void
    {
        $roomManager = new RoomManager();
        $broadcaster = new MessageBroadcaster($roomManager);

        $sentMessages = [];
        
        $conn1 = $this->createMock(ConnectionInterface::class);
        $conn1->method('getId')->willReturn('conn1');
        $conn1->method('send')->willReturnCallback(function ($msg) use (&$sentMessages) {
            $sentMessages['conn1'][] = $msg;
            return true;
        });
        $conn1->method('getRooms')->willReturn([]);

        $conn2 = $this->createMock(ConnectionInterface::class);
        $conn2->method('getId')->willReturn('conn2');
        $conn2->method('send')->willReturnCallback(function ($msg) use (&$sentMessages) {
            $sentMessages['conn2'][] = $msg;
            return true;
        });
        $conn2->method('getRooms')->willReturn([]);

        $roomManager->join($conn1, 'room1');
        $roomManager->join($conn2, 'room1');

        $sent = $broadcaster->toRoom('room1', ['type' => 'test'], ['conn1']);

        $this->assertEquals(1, $sent);
        $this->assertArrayNotHasKey('conn1', $sentMessages);
        $this->assertCount(1, $sentMessages['conn2']);
    }

    public function testBroadcasterToFiltered(): void
    {
        $roomManager = new RoomManager();
        $broadcaster = new MessageBroadcaster($roomManager);

        $sentMessages = [];
        
        $conn1 = $this->createMock(ConnectionInterface::class);
        $conn1->method('getId')->willReturn('conn1');
        $conn1->method('send')->willReturnCallback(function ($msg) use (&$sentMessages) {
            $sentMessages['conn1'][] = $msg;
            return true;
        });
        $conn1->method('getMetadata')->willReturn(['role' => 'admin']);

        $conn2 = $this->createMock(ConnectionInterface::class);
        $conn2->method('getId')->willReturn('conn2');
        $conn2->method('send')->willReturnCallback(function ($msg) use (&$sentMessages) {
            $sentMessages['conn2'][] = $msg;
            return true;
        });
        $conn2->method('getMetadata')->willReturn(['role' => 'user']);

        $connections = ['conn1' => $conn1, 'conn2' => $conn2];

        // Only send to admins
        $sent = $broadcaster->toFiltered($connections, ['type' => 'admin_only'], function ($conn) {
            return ($conn->getMetadata()['role'] ?? '') === 'admin';
        });

        $this->assertEquals(1, $sent);
        $this->assertCount(1, $sentMessages['conn1']);
        $this->assertArrayNotHasKey('conn2', $sentMessages);
    }

    public function testBroadcasterStats(): void
    {
        $roomManager = new RoomManager();
        $broadcaster = new MessageBroadcaster($roomManager);

        $conn1 = $this->createMock(ConnectionInterface::class);
        $conn1->method('getId')->willReturn('conn1');
        $conn1->method('send')->willReturn(true);
        $conn1->method('getRooms')->willReturn([]);

        $roomManager->join($conn1, 'room1');
        $broadcaster->toRoom('room1', ['test' => 'data']);

        $stats = $broadcaster->getStats();

        $this->assertEquals(1, $stats['messages_sent']);
        $this->assertEquals(1, $stats['broadcasts']);
    }

    // ==================== HeartbeatManager Tests ====================

    public function testHeartbeatAddRemoveConnection(): void
    {
        $heartbeat = new HeartbeatManager(30, 60);
        $connection = $this->createMockConnection('conn1');

        $heartbeat->addConnection($connection);
        $this->assertCount(1, $heartbeat->getConnections());

        $heartbeat->removeConnection($connection);
        $this->assertCount(0, $heartbeat->getConnections());
    }

    public function testHeartbeatIsPong(): void
    {
        $heartbeat = new HeartbeatManager(30, 60);

        $this->assertTrue($heartbeat->isPong('{"type":"pong"}'));
        $this->assertTrue($heartbeat->isPong('pong'));
        $this->assertFalse($heartbeat->isPong('{"type":"message"}'));
        $this->assertFalse($heartbeat->isPong('hello'));
    }

    public function testHeartbeatStats(): void
    {
        $heartbeat = new HeartbeatManager(30, 60);
        $connection = $this->createMockConnection('conn1');

        $heartbeat->addConnection($connection);
        $stats = $heartbeat->getStats();

        $this->assertEquals(1, $stats['active_connections']);
        $this->assertEquals(30, $stats['interval']);
        $this->assertEquals(60, $stats['timeout']);
    }

    // ==================== ReconnectionManager Tests ====================

    public function testReconnectionCreateSession(): void
    {
        $reconnection = new ReconnectionManager(300, 5);
        
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('getId')->willReturn('conn1');
        $connection->method('getRooms')->willReturn(['room1', 'room2']);
        $connection->method('getMetadata')->willReturn(['user_id' => 123]);
        $connection->method('getLastActivity')->willReturn(time());

        $token = $reconnection->createSession($connection);

        $this->assertNotEmpty($token);
        $this->assertTrue($reconnection->validateToken($token));
    }

    public function testReconnectionValidateToken(): void
    {
        $reconnection = new ReconnectionManager(300, 5);
        
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('getId')->willReturn('conn1');
        $connection->method('getRooms')->willReturn([]);
        $connection->method('getMetadata')->willReturn([]);
        $connection->method('getLastActivity')->willReturn(time());

        $token = $reconnection->createSession($connection);

        $this->assertTrue($reconnection->validateToken($token));
        $this->assertFalse($reconnection->validateToken('invalid-token'));
    }

    public function testReconnectionReconnect(): void
    {
        $reconnection = new ReconnectionManager(300, 5);
        
        $oldConnection = $this->createMock(ConnectionInterface::class);
        $oldConnection->method('getId')->willReturn('old-conn');
        $oldConnection->method('getRooms')->willReturn(['room1']);
        $oldConnection->method('getMetadata')->willReturn(['user_id' => 123]);
        $oldConnection->method('getLastActivity')->willReturn(time());

        $token = $reconnection->createSession($oldConnection);

        $newConnection = $this->createMock(ConnectionInterface::class);
        $newConnection->method('getId')->willReturn('new-conn');
        $newConnection->method('getRooms')->willReturn([]);
        $newConnection->method('getMetadata')->willReturn([]);

        $session = $reconnection->reconnect($token, $newConnection);

        $this->assertNotNull($session);
        $this->assertEquals(['room1'], $session['rooms']);
        $this->assertEquals(['user_id' => 123], $session['metadata']);
        $this->assertEquals('old-conn', $session['previous_connection_id']);
    }

    public function testReconnectionMaxAttempts(): void
    {
        $reconnection = new ReconnectionManager(300, 2);
        
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('getId')->willReturn('conn1');
        $connection->method('getRooms')->willReturn([]);
        $connection->method('getMetadata')->willReturn([]);
        $connection->method('getLastActivity')->willReturn(time());

        $token = $reconnection->createSession($connection);

        $newConn = $this->createMock(ConnectionInterface::class);
        $newConn->method('getId')->willReturn('new');
        $newConn->method('getRooms')->willReturn([]);
        $newConn->method('getMetadata')->willReturn([]);

        // First two attempts should succeed
        $this->assertNotNull($reconnection->reconnect($token, $newConn));
        $this->assertNotNull($reconnection->reconnect($token, $newConn));
        
        // Third attempt should fail
        $this->assertNull($reconnection->reconnect($token, $newConn));
    }

    public function testReconnectionRemoveSession(): void
    {
        $reconnection = new ReconnectionManager(300, 5);
        
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('getId')->willReturn('conn1');
        $connection->method('getRooms')->willReturn([]);
        $connection->method('getMetadata')->willReturn([]);
        $connection->method('getLastActivity')->willReturn(time());

        $token = $reconnection->createSession($connection);
        $this->assertTrue($reconnection->validateToken($token));

        $reconnection->removeSessionByToken($token);
        $this->assertFalse($reconnection->validateToken($token));
    }

    public function testReconnectionStats(): void
    {
        $reconnection = new ReconnectionManager(300, 5);
        
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('getId')->willReturn('conn1');
        $connection->method('getRooms')->willReturn([]);
        $connection->method('getMetadata')->willReturn([]);
        $connection->method('getLastActivity')->willReturn(time());

        $reconnection->createSession($connection);
        $stats = $reconnection->getStats();

        $this->assertEquals(1, $stats['sessions_created']);
        $this->assertEquals(1, $stats['active_sessions']);
    }
}
