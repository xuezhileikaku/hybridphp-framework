<?php

declare(strict_types=1);

namespace HybridPHP\Core\Server\Http2;

use Amp\DeferredFuture;
use Amp\Future;
use function Amp\async;

/**
 * HTTP/2 Flow Controller
 * 
 * Manages flow control for HTTP/2 connections and streams.
 * Implements RFC 7540 Section 5.2 flow control mechanism.
 * 
 * Features:
 * - Connection-level flow control
 * - Stream-level flow control
 * - Window update management
 * - Blocked stream tracking
 */
class FlowController
{
    /** @var int Default initial window size (64KB) */
    public const DEFAULT_WINDOW_SIZE = 65535;
    
    /** @var int Maximum window size (2^31 - 1) */
    public const MAX_WINDOW_SIZE = 2147483647;
    
    /** @var int Minimum window threshold for auto-update */
    public const WINDOW_UPDATE_THRESHOLD = 16384;

    private int $connectionSendWindow;
    private int $connectionReceiveWindow;
    private int $initialWindowSize;
    
    /** @var array<int, int> Stream send windows */
    private array $streamSendWindows = [];
    
    /** @var array<int, int> Stream receive windows */
    private array $streamReceiveWindows = [];
    
    /** @var array<int, DeferredFuture[]> Blocked senders waiting for window */
    private array $blockedSenders = [];
    
    /** @var array Statistics */
    private array $stats = [
        'window_updates_sent' => 0,
        'window_updates_received' => 0,
        'bytes_sent' => 0,
        'bytes_received' => 0,
        'blocked_count' => 0,
        'unblocked_count' => 0,
    ];

    public function __construct(int $initialWindowSize = self::DEFAULT_WINDOW_SIZE)
    {
        $this->initialWindowSize = $initialWindowSize;
        $this->connectionSendWindow = $initialWindowSize;
        $this->connectionReceiveWindow = $initialWindowSize;
    }

    /**
     * Initialize flow control for a new stream
     */
    public function initStream(int $streamId): void
    {
        $this->streamSendWindows[$streamId] = $this->initialWindowSize;
        $this->streamReceiveWindows[$streamId] = $this->initialWindowSize;
        $this->blockedSenders[$streamId] = [];
    }

    /**
     * Remove flow control for a closed stream
     */
    public function removeStream(int $streamId): void
    {
        // Unblock any waiting senders with error
        if (isset($this->blockedSenders[$streamId])) {
            foreach ($this->blockedSenders[$streamId] as $deferred) {
                $deferred->error(new \RuntimeException("Stream {$streamId} closed"));
            }
        }
        
        unset($this->streamSendWindows[$streamId]);
        unset($this->streamReceiveWindows[$streamId]);
        unset($this->blockedSenders[$streamId]);
    }

    /**
     * Request to send data - may block if window is insufficient
     * 
     * @param int $streamId Stream ID (0 for connection-level only)
     * @param int $size Number of bytes to send
     * @return Future<int> Resolves with bytes allowed to send
     */
    public function requestSend(int $streamId, int $size): Future
    {
        return async(function () use ($streamId, $size): int {
            // Check connection window
            if ($this->connectionSendWindow <= 0) {
                $this->stats['blocked_count']++;
                $deferred = new DeferredFuture();
                $this->blockedSenders[0][] = $deferred;
                $deferred->getFuture()->await();
                $this->stats['unblocked_count']++;
            }
            
            // Check stream window (if not connection-level)
            if ($streamId > 0) {
                $streamWindow = $this->streamSendWindows[$streamId] ?? 0;
                if ($streamWindow <= 0) {
                    $this->stats['blocked_count']++;
                    $deferred = new DeferredFuture();
                    $this->blockedSenders[$streamId][] = $deferred;
                    $deferred->getFuture()->await();
                    $this->stats['unblocked_count']++;
                }
            }
            
            // Calculate allowed bytes
            $allowed = $this->connectionSendWindow;
            if ($streamId > 0) {
                $allowed = min($allowed, $this->streamSendWindows[$streamId] ?? 0);
            }
            $allowed = min($allowed, $size);
            
            return max(0, $allowed);
        });
    }


    /**
     * Consume send window after sending data
     */
    public function consumeSendWindow(int $streamId, int $size): void
    {
        $this->connectionSendWindow -= $size;
        
        if ($streamId > 0 && isset($this->streamSendWindows[$streamId])) {
            $this->streamSendWindows[$streamId] -= $size;
        }
        
        $this->stats['bytes_sent'] += $size;
    }

    /**
     * Consume receive window after receiving data
     */
    public function consumeReceiveWindow(int $streamId, int $size): void
    {
        $this->connectionReceiveWindow -= $size;
        
        if ($streamId > 0 && isset($this->streamReceiveWindows[$streamId])) {
            $this->streamReceiveWindows[$streamId] -= $size;
        }
        
        $this->stats['bytes_received'] += $size;
    }

    /**
     * Process incoming WINDOW_UPDATE frame
     */
    public function processWindowUpdate(int $streamId, int $increment): void
    {
        if ($streamId === 0) {
            // Connection-level update
            $this->connectionSendWindow += $increment;
            if ($this->connectionSendWindow > self::MAX_WINDOW_SIZE) {
                $this->connectionSendWindow = self::MAX_WINDOW_SIZE;
            }
            
            // Unblock connection-level waiters
            $this->unblockSenders(0);
        } else {
            // Stream-level update
            if (isset($this->streamSendWindows[$streamId])) {
                $this->streamSendWindows[$streamId] += $increment;
                if ($this->streamSendWindows[$streamId] > self::MAX_WINDOW_SIZE) {
                    $this->streamSendWindows[$streamId] = self::MAX_WINDOW_SIZE;
                }
                
                // Unblock stream-level waiters
                $this->unblockSenders($streamId);
            }
        }
        
        $this->stats['window_updates_received']++;
    }

    /**
     * Generate WINDOW_UPDATE if receive window is low
     * 
     * @return array<int, int> Map of streamId => increment for WINDOW_UPDATE frames to send
     */
    public function generateWindowUpdates(): array
    {
        $updates = [];
        
        // Check connection receive window
        if ($this->connectionReceiveWindow < self::WINDOW_UPDATE_THRESHOLD) {
            $increment = $this->initialWindowSize - $this->connectionReceiveWindow;
            $this->connectionReceiveWindow += $increment;
            $updates[0] = $increment;
            $this->stats['window_updates_sent']++;
        }
        
        // Check stream receive windows
        foreach ($this->streamReceiveWindows as $streamId => $window) {
            if ($window < self::WINDOW_UPDATE_THRESHOLD) {
                $increment = $this->initialWindowSize - $window;
                $this->streamReceiveWindows[$streamId] += $increment;
                $updates[$streamId] = $increment;
                $this->stats['window_updates_sent']++;
            }
        }
        
        return $updates;
    }

    /**
     * Unblock senders waiting for window
     */
    private function unblockSenders(int $streamId): void
    {
        if (!isset($this->blockedSenders[$streamId])) {
            return;
        }
        
        $window = $streamId === 0 
            ? $this->connectionSendWindow 
            : ($this->streamSendWindows[$streamId] ?? 0);
        
        while (!empty($this->blockedSenders[$streamId]) && $window > 0) {
            $deferred = array_shift($this->blockedSenders[$streamId]);
            $deferred->complete(null);
        }
    }

    /**
     * Get connection send window
     */
    public function getConnectionSendWindow(): int
    {
        return $this->connectionSendWindow;
    }

    /**
     * Get connection receive window
     */
    public function getConnectionReceiveWindow(): int
    {
        return $this->connectionReceiveWindow;
    }

    /**
     * Get stream send window
     */
    public function getStreamSendWindow(int $streamId): int
    {
        return $this->streamSendWindows[$streamId] ?? 0;
    }

    /**
     * Get stream receive window
     */
    public function getStreamReceiveWindow(int $streamId): int
    {
        return $this->streamReceiveWindows[$streamId] ?? 0;
    }

    /**
     * Check if connection can send
     */
    public function canConnectionSend(): bool
    {
        return $this->connectionSendWindow > 0;
    }

    /**
     * Check if stream can send
     */
    public function canStreamSend(int $streamId): bool
    {
        return $this->connectionSendWindow > 0 
            && ($this->streamSendWindows[$streamId] ?? 0) > 0;
    }

    /**
     * Update initial window size (from SETTINGS frame)
     */
    public function updateInitialWindowSize(int $newSize): void
    {
        $delta = $newSize - $this->initialWindowSize;
        $this->initialWindowSize = $newSize;
        
        // Adjust all stream windows
        foreach ($this->streamSendWindows as $streamId => &$window) {
            $window += $delta;
            if ($window > self::MAX_WINDOW_SIZE) {
                $window = self::MAX_WINDOW_SIZE;
            }
            
            // Unblock if window increased
            if ($delta > 0) {
                $this->unblockSenders($streamId);
            }
        }
    }

    /**
     * Get statistics
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'connection_send_window' => $this->connectionSendWindow,
            'connection_receive_window' => $this->connectionReceiveWindow,
            'initial_window_size' => $this->initialWindowSize,
            'active_streams' => count($this->streamSendWindows),
            'blocked_streams' => count(array_filter($this->blockedSenders, fn($b) => !empty($b))),
        ]);
    }

    /**
     * Reset statistics
     */
    public function resetStats(): void
    {
        $this->stats = [
            'window_updates_sent' => 0,
            'window_updates_received' => 0,
            'bytes_sent' => 0,
            'bytes_received' => 0,
            'blocked_count' => 0,
            'unblocked_count' => 0,
        ];
    }
}
