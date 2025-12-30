<?php

declare(strict_types=1);

namespace HybridPHP\Core\Server\WebSocket;

/**
 * WebSocket Reconnection Manager
 * 
 * Manages client reconnection with session persistence,
 * allowing clients to resume their state after disconnection.
 */
class ReconnectionManager
{
    /**
     * Session storage
     * @var array<string, array>
     */
    protected array $sessions = [];

    /**
     * Token to session ID mapping
     * @var array<string, string>
     */
    protected array $tokenMap = [];

    /**
     * Session TTL in seconds
     */
    protected int $sessionTtl;

    /**
     * Maximum reconnection attempts
     */
    protected int $maxAttempts;

    /**
     * Cleanup timer ID
     */
    protected ?int $cleanupTimerId = null;

    /**
     * Statistics
     */
    protected array $stats = [
        'sessions_created' => 0,
        'reconnections_successful' => 0,
        'reconnections_failed' => 0,
        'sessions_expired' => 0,
    ];

    public function __construct(int $sessionTtl = 300, int $maxAttempts = 5)
    {
        $this->sessionTtl = $sessionTtl;
        $this->maxAttempts = $maxAttempts;
    }

    /**
     * Create a reconnection session for a connection
     * 
     * @return string Reconnection token
     */
    public function createSession(ConnectionInterface $connection, array $additionalData = []): string
    {
        $token = $this->generateToken();
        $sessionId = $connection->getId();

        $this->sessions[$sessionId] = [
            'token' => $token,
            'connection_id' => $sessionId,
            'created_at' => time(),
            'expires_at' => time() + $this->sessionTtl,
            'rooms' => $connection->getRooms(),
            'metadata' => $connection->getMetadata(),
            'additional_data' => $additionalData,
            'reconnect_attempts' => 0,
            'last_activity' => $connection->getLastActivity(),
        ];

        $this->tokenMap[$token] = $sessionId;
        $this->stats['sessions_created']++;

        return $token;
    }

    /**
     * Attempt to reconnect using a token
     * 
     * @return array|null Session data if successful, null otherwise
     */
    public function reconnect(string $token, ConnectionInterface $newConnection): ?array
    {
        if (!isset($this->tokenMap[$token])) {
            $this->stats['reconnections_failed']++;
            return null;
        }

        $sessionId = $this->tokenMap[$token];
        
        if (!isset($this->sessions[$sessionId])) {
            unset($this->tokenMap[$token]);
            $this->stats['reconnections_failed']++;
            return null;
        }

        $session = $this->sessions[$sessionId];

        // Check if session expired
        if (time() > $session['expires_at']) {
            $this->removeSession($sessionId);
            $this->stats['reconnections_failed']++;
            $this->stats['sessions_expired']++;
            return null;
        }

        // Check max attempts
        if ($session['reconnect_attempts'] >= $this->maxAttempts) {
            $this->removeSession($sessionId);
            $this->stats['reconnections_failed']++;
            return null;
        }

        // Update session with new connection info
        $this->sessions[$sessionId]['reconnect_attempts']++;
        
        // Restore metadata to new connection
        foreach ($session['metadata'] as $key => $value) {
            $newConnection->setMetadata($key, $value);
        }

        $this->stats['reconnections_successful']++;

        return [
            'rooms' => $session['rooms'],
            'metadata' => $session['metadata'],
            'additional_data' => $session['additional_data'],
            'previous_connection_id' => $session['connection_id'],
        ];
    }

    /**
     * Validate a reconnection token
     */
    public function validateToken(string $token): bool
    {
        if (!isset($this->tokenMap[$token])) {
            return false;
        }

        $sessionId = $this->tokenMap[$token];
        
        if (!isset($this->sessions[$sessionId])) {
            return false;
        }

        return time() <= $this->sessions[$sessionId]['expires_at'];
    }

    /**
     * Get session data by token
     */
    public function getSession(string $token): ?array
    {
        if (!isset($this->tokenMap[$token])) {
            return null;
        }

        $sessionId = $this->tokenMap[$token];
        return $this->sessions[$sessionId] ?? null;
    }

    /**
     * Update session data
     */
    public function updateSession(string $token, array $data): bool
    {
        if (!isset($this->tokenMap[$token])) {
            return false;
        }

        $sessionId = $this->tokenMap[$token];
        
        if (!isset($this->sessions[$sessionId])) {
            return false;
        }

        $this->sessions[$sessionId] = array_merge($this->sessions[$sessionId], $data);
        return true;
    }

    /**
     * Extend session expiration
     */
    public function extendSession(string $token, ?int $additionalTime = null): bool
    {
        if (!isset($this->tokenMap[$token])) {
            return false;
        }

        $sessionId = $this->tokenMap[$token];
        
        if (!isset($this->sessions[$sessionId])) {
            return false;
        }

        $extension = $additionalTime ?? $this->sessionTtl;
        $this->sessions[$sessionId]['expires_at'] = time() + $extension;
        
        return true;
    }

    /**
     * Remove a session
     */
    public function removeSession(string $sessionId): bool
    {
        if (!isset($this->sessions[$sessionId])) {
            return false;
        }

        $token = $this->sessions[$sessionId]['token'];
        unset($this->sessions[$sessionId]);
        unset($this->tokenMap[$token]);

        return true;
    }

    /**
     * Remove session by token
     */
    public function removeSessionByToken(string $token): bool
    {
        if (!isset($this->tokenMap[$token])) {
            return false;
        }

        $sessionId = $this->tokenMap[$token];
        return $this->removeSession($sessionId);
    }

    /**
     * Clean up expired sessions
     * 
     * @return int Number of sessions cleaned up
     */
    public function cleanup(): int
    {
        $now = time();
        $cleaned = 0;

        foreach ($this->sessions as $sessionId => $session) {
            if ($now > $session['expires_at']) {
                $this->removeSession($sessionId);
                $cleaned++;
                $this->stats['sessions_expired']++;
            }
        }

        return $cleaned;
    }

    /**
     * Start automatic cleanup timer
     */
    public function startCleanup(int $interval = 60): void
    {
        if ($this->cleanupTimerId !== null) {
            return;
        }

        $this->cleanupTimerId = \Workerman\Timer::add($interval, function () {
            $this->cleanup();
        });
    }

    /**
     * Stop automatic cleanup timer
     */
    public function stopCleanup(): void
    {
        if ($this->cleanupTimerId !== null) {
            \Workerman\Timer::del($this->cleanupTimerId);
            $this->cleanupTimerId = null;
        }
    }

    /**
     * Generate a secure reconnection token
     */
    protected function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Get statistics
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'active_sessions' => count($this->sessions),
            'session_ttl' => $this->sessionTtl,
            'max_attempts' => $this->maxAttempts,
        ]);
    }

    /**
     * Reset statistics
     */
    public function resetStats(): void
    {
        $this->stats = [
            'sessions_created' => 0,
            'reconnections_successful' => 0,
            'reconnections_failed' => 0,
            'sessions_expired' => 0,
        ];
    }

    /**
     * Get all active sessions
     */
    public function getAllSessions(): array
    {
        return $this->sessions;
    }

    /**
     * Set session TTL
     */
    public function setSessionTtl(int $ttl): void
    {
        $this->sessionTtl = $ttl;
    }

    /**
     * Set max reconnection attempts
     */
    public function setMaxAttempts(int $attempts): void
    {
        $this->maxAttempts = $attempts;
    }
}
