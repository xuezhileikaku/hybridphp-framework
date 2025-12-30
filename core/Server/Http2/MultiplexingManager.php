<?php

declare(strict_types=1);

namespace HybridPHP\Core\Server\Http2;

use Amp\Future;
use Amp\DeferredFuture;
use function Amp\async;
use function Amp\delay;

/**
 * HTTP/2 Multiplexing Manager
 * 
 * Manages concurrent stream processing for HTTP/2 multiplexing.
 * Handles:
 * - Concurrent request/response processing over single connection
 * - Stream scheduling based on priority and dependencies
 * - Connection-level and stream-level flow control
 * - Fair bandwidth allocation across streams
 */
class MultiplexingManager
{
    private StreamManager $streamManager;
    private Http2Config $config;
    
    /** @var array<int, DeferredFuture> Active stream futures */
    private array $pendingStreams = [];
    
    /** @var array<int, array> Stream processing queue */
    private array $processingQueue = [];
    
    /** @var array<int, callable> Stream handlers */
    private array $streamHandlers = [];
    
    /** @var int Connection-level window size */
    private int $connectionWindow;
    
    /** @var int Maximum concurrent processing */
    private int $maxConcurrentProcessing;
    
    /** @var int Currently processing count */
    private int $currentlyProcessing = 0;
    
    /** @var array Statistics */
    private array $stats = [
        'streams_created' => 0,
        'streams_completed' => 0,
        'streams_cancelled' => 0,
        'bytes_sent' => 0,
        'bytes_received' => 0,
        'window_updates' => 0,
        'priority_changes' => 0,
    ];

    public function __construct(StreamManager $streamManager, Http2Config $config)
    {
        $this->streamManager = $streamManager;
        $this->config = $config;
        $this->connectionWindow = $config->getInitialWindowSize();
        $this->maxConcurrentProcessing = $config->getMaxConcurrentStreams();
    }

    /**
     * Create a new multiplexed stream
     */
    public function createStream(?int $streamId = null, int $priority = 16): Http2Stream
    {
        $stream = $this->streamManager->createStream($streamId);
        $this->streamManager->setStreamPriority($stream->getId(), $priority);
        $this->stats['streams_created']++;
        
        return $stream;
    }

    /**
     * Submit a stream for processing
     * 
     * @param int $streamId Stream ID
     * @param callable $handler Handler function that processes the stream
     * @return Future<mixed> Future that resolves when stream processing completes
     */
    public function submitStream(int $streamId, callable $handler): Future
    {
        $deferred = new DeferredFuture();
        
        $this->pendingStreams[$streamId] = $deferred;
        $this->streamHandlers[$streamId] = $handler;
        
        // Add to processing queue with priority
        $priority = $this->streamManager->getStreamPriority($streamId);
        $this->processingQueue[$streamId] = [
            'stream_id' => $streamId,
            'priority' => $priority['weight'] ?? 16,
            'dependency' => $priority['dependency'] ?? 0,
            'submitted_at' => microtime(true),
        ];
        
        // Trigger processing
        $this->processQueue();
        
        return $deferred->getFuture();
    }


    /**
     * Process the stream queue based on priority
     */
    private function processQueue(): void
    {
        if (empty($this->processingQueue)) {
            return;
        }
        
        // Sort queue by priority (higher weight = higher priority)
        uasort($this->processingQueue, function ($a, $b) {
            // First check dependencies
            if ($a['dependency'] === $b['stream_id']) {
                return 1; // a depends on b, process b first
            }
            if ($b['dependency'] === $a['stream_id']) {
                return -1; // b depends on a, process a first
            }
            // Then sort by weight
            return $b['priority'] <=> $a['priority'];
        });
        
        // Process streams up to max concurrent limit
        foreach ($this->processingQueue as $streamId => $queueItem) {
            if ($this->currentlyProcessing >= $this->maxConcurrentProcessing) {
                break;
            }
            
            // Check if dependency is satisfied
            if ($queueItem['dependency'] > 0 && isset($this->processingQueue[$queueItem['dependency']])) {
                continue; // Dependency not yet processed
            }
            
            // Remove from queue and start processing
            unset($this->processingQueue[$streamId]);
            $this->currentlyProcessing++;
            
            async(function () use ($streamId) {
                try {
                    $this->processStream($streamId);
                } finally {
                    $this->currentlyProcessing--;
                    // Continue processing queue
                    $this->processQueue();
                }
            });
        }
    }

    /**
     * Process a single stream
     */
    private function processStream(int $streamId): void
    {
        $stream = $this->streamManager->getStream($streamId);
        if (!$stream) {
            $this->cancelStream($streamId, 'Stream not found');
            return;
        }
        
        $handler = $this->streamHandlers[$streamId] ?? null;
        if (!$handler) {
            $this->cancelStream($streamId, 'No handler registered');
            return;
        }
        
        $deferred = $this->pendingStreams[$streamId] ?? null;
        if (!$deferred) {
            return;
        }
        
        try {
            // Open the stream
            $stream->open();
            
            // Execute the handler
            $result = $handler($stream, $this);
            
            // If handler returns a Future, await it
            if ($result instanceof Future) {
                $result = $result->await();
            }
            
            // Complete the stream
            $stream->closeLocal();
            $this->stats['streams_completed']++;
            
            // Resolve the deferred
            $deferred->complete($result);
            
        } catch (\Throwable $e) {
            $stream->close();
            $this->stats['streams_cancelled']++;
            $deferred->error($e);
            
        } finally {
            // Cleanup
            unset($this->pendingStreams[$streamId]);
            unset($this->streamHandlers[$streamId]);
        }
    }

    /**
     * Cancel a stream
     */
    public function cancelStream(int $streamId, string $reason = 'Cancelled'): void
    {
        $deferred = $this->pendingStreams[$streamId] ?? null;
        
        if ($deferred) {
            $deferred->error(new \RuntimeException("Stream {$streamId} cancelled: {$reason}"));
        }
        
        $this->streamManager->closeStream($streamId);
        $this->stats['streams_cancelled']++;
        
        unset($this->pendingStreams[$streamId]);
        unset($this->streamHandlers[$streamId]);
        unset($this->processingQueue[$streamId]);
    }

    /**
     * Update stream priority
     */
    public function updatePriority(int $streamId, int $weight, int $dependency = 0, bool $exclusive = false): void
    {
        $this->streamManager->setStreamPriority($streamId, $weight, $dependency, $exclusive);
        
        // Update queue if stream is pending
        if (isset($this->processingQueue[$streamId])) {
            $this->processingQueue[$streamId]['priority'] = $weight;
            $this->processingQueue[$streamId]['dependency'] = $dependency;
        }
        
        $this->stats['priority_changes']++;
    }


    /**
     * Send data on a stream with flow control
     * 
     * @param int $streamId Stream ID
     * @param string $data Data to send
     * @return Future<int> Bytes actually sent
     */
    public function sendData(int $streamId, string $data): Future
    {
        return async(function () use ($streamId, $data): int {
            $stream = $this->streamManager->getStream($streamId);
            if (!$stream || !$stream->canSend()) {
                throw new \RuntimeException("Stream {$streamId} cannot send data");
            }
            
            $dataLength = strlen($data);
            $bytesSent = 0;
            $offset = 0;
            
            while ($offset < $dataLength) {
                // Calculate how much we can send based on flow control
                $availableWindow = min(
                    $this->connectionWindow,
                    $stream->getWindowSize(),
                    $this->config->getMaxFrameSize()
                );
                
                if ($availableWindow <= 0) {
                    // Wait for window update
                    delay(0.001); // 1ms delay
                    continue;
                }
                
                $chunkSize = min($availableWindow, $dataLength - $offset);
                $chunk = substr($data, $offset, $chunkSize);
                
                // Consume windows
                $this->connectionWindow -= $chunkSize;
                $stream->consumeWindow($chunkSize);
                
                $bytesSent += $chunkSize;
                $offset += $chunkSize;
            }
            
            $this->stats['bytes_sent'] += $bytesSent;
            return $bytesSent;
        });
    }

    /**
     * Receive data on a stream
     */
    public function receiveData(int $streamId, string $data): void
    {
        $stream = $this->streamManager->getStream($streamId);
        if (!$stream || !$stream->canReceive()) {
            return;
        }
        
        $stream->appendBody($data);
        $this->stats['bytes_received'] += strlen($data);
    }

    /**
     * Update connection window size
     */
    public function updateConnectionWindow(int $increment): void
    {
        $this->connectionWindow += $increment;
        $this->stats['window_updates']++;
        
        // Resume any blocked streams
        $this->processQueue();
    }

    /**
     * Update stream window size
     */
    public function updateStreamWindow(int $streamId, int $increment): void
    {
        $this->streamManager->updateStreamWindow($streamId, $increment);
        $this->stats['window_updates']++;
    }

    /**
     * Get connection window size
     */
    public function getConnectionWindow(): int
    {
        return $this->connectionWindow;
    }

    /**
     * Get stream window size
     */
    public function getStreamWindow(int $streamId): int
    {
        $stream = $this->streamManager->getStream($streamId);
        return $stream ? $stream->getWindowSize() : 0;
    }

    /**
     * Check if stream can send data
     */
    public function canSend(int $streamId): bool
    {
        $stream = $this->streamManager->getStream($streamId);
        if (!$stream || !$stream->canSend()) {
            return false;
        }
        
        return $this->connectionWindow > 0 && $stream->getWindowSize() > 0;
    }

    /**
     * Get active stream count
     */
    public function getActiveStreamCount(): int
    {
        return $this->streamManager->getActiveStreamCount();
    }

    /**
     * Get pending stream count
     */
    public function getPendingStreamCount(): int
    {
        return count($this->processingQueue);
    }

    /**
     * Get currently processing count
     */
    public function getCurrentlyProcessingCount(): int
    {
        return $this->currentlyProcessing;
    }

    /**
     * Get all active streams sorted by priority
     */
    public function getActiveStreamsByPriority(): array
    {
        return $this->streamManager->getStreamsByPriority();
    }

    /**
     * Close all streams
     */
    public function closeAllStreams(): void
    {
        // Cancel all pending streams
        foreach (array_keys($this->pendingStreams) as $streamId) {
            $this->cancelStream($streamId, 'Connection closed');
        }
        
        $this->streamManager->closeAllStreams();
        $this->processingQueue = [];
    }

    /**
     * Get statistics
     */
    public function getStats(): array
    {
        return array_merge($this->stats, [
            'active_streams' => $this->getActiveStreamCount(),
            'pending_streams' => $this->getPendingStreamCount(),
            'currently_processing' => $this->currentlyProcessing,
            'connection_window' => $this->connectionWindow,
            'max_concurrent' => $this->maxConcurrentProcessing,
        ], $this->streamManager->getStats());
    }

    /**
     * Get stream manager
     */
    public function getStreamManager(): StreamManager
    {
        return $this->streamManager;
    }
}
