<?php

declare(strict_types=1);

namespace HybridPHP\Core\Server\Http2;

/**
 * HTTP/2 Stream Manager
 * 
 * Manages HTTP/2 streams for multiplexing support.
 * Handles stream lifecycle, prioritization, and flow control.
 */
class StreamManager
{
    private array $streams = [];
    private int $maxConcurrentStreams;
    private int $initialWindowSize;
    private int $connectionWindowSize;
    private int $nextStreamId = 1;
    private array $streamPriorities = [];

    public function __construct(Http2Config $config)
    {
        $this->maxConcurrentStreams = $config->getMaxConcurrentStreams();
        $this->initialWindowSize = $config->getInitialWindowSize();
        $this->connectionWindowSize = $config->getInitialWindowSize();
    }

    /**
     * Create a new stream
     */
    public function createStream(int $streamId = null): Http2Stream
    {
        if ($streamId === null) {
            $streamId = $this->nextStreamId;
            $this->nextStreamId += 2; // Client streams are odd, server streams are even
        }

        if ($this->getActiveStreamCount() >= $this->maxConcurrentStreams) {
            throw new \RuntimeException("Maximum concurrent streams ({$this->maxConcurrentStreams}) exceeded");
        }

        $stream = new Http2Stream($streamId, $this->initialWindowSize);
        $this->streams[$streamId] = $stream;
        $this->streamPriorities[$streamId] = [
            'weight' => 16, // Default weight
            'dependency' => 0,
            'exclusive' => false,
        ];

        return $stream;
    }

    /**
     * Get a stream by ID
     */
    public function getStream(int $streamId): ?Http2Stream
    {
        return $this->streams[$streamId] ?? null;
    }

    /**
     * Close a stream
     */
    public function closeStream(int $streamId): void
    {
        if (isset($this->streams[$streamId])) {
            $this->streams[$streamId]->close();
            unset($this->streams[$streamId]);
            unset($this->streamPriorities[$streamId]);
        }
    }

    /**
     * Get active stream count
     */
    public function getActiveStreamCount(): int
    {
        return count(array_filter($this->streams, fn($s) => $s->isOpen()));
    }

    /**
     * Set stream priority
     */
    public function setStreamPriority(int $streamId, int $weight, int $dependency = 0, bool $exclusive = false): void
    {
        if (!isset($this->streams[$streamId])) {
            return;
        }

        $weight = max(1, min(256, $weight)); // Weight must be 1-256

        $this->streamPriorities[$streamId] = [
            'weight' => $weight,
            'dependency' => $dependency,
            'exclusive' => $exclusive,
        ];

        // Handle exclusive dependency
        if ($exclusive && $dependency > 0) {
            foreach ($this->streamPriorities as $id => &$priority) {
                if ($id !== $streamId && $priority['dependency'] === $dependency) {
                    $priority['dependency'] = $streamId;
                }
            }
        }
    }

    /**
     * Get stream priority
     */
    public function getStreamPriority(int $streamId): ?array
    {
        return $this->streamPriorities[$streamId] ?? null;
    }

    /**
     * Update connection window size
     */
    public function updateConnectionWindow(int $increment): void
    {
        $this->connectionWindowSize += $increment;
    }

    /**
     * Update stream window size
     */
    public function updateStreamWindow(int $streamId, int $increment): void
    {
        if (isset($this->streams[$streamId])) {
            $this->streams[$streamId]->updateWindow($increment);
        }
    }

    /**
     * Get connection window size
     */
    public function getConnectionWindowSize(): int
    {
        return $this->connectionWindowSize;
    }

    /**
     * Consume connection window
     */
    public function consumeConnectionWindow(int $size): bool
    {
        if ($size > $this->connectionWindowSize) {
            return false;
        }
        $this->connectionWindowSize -= $size;
        return true;
    }

    /**
     * Get all streams
     */
    public function getAllStreams(): array
    {
        return $this->streams;
    }

    /**
     * Get streams sorted by priority
     */
    public function getStreamsByPriority(): array
    {
        $streams = $this->streams;
        
        uasort($streams, function($a, $b) {
            $priorityA = $this->streamPriorities[$a->getId()] ?? ['weight' => 16];
            $priorityB = $this->streamPriorities[$b->getId()] ?? ['weight' => 16];
            return $priorityB['weight'] <=> $priorityA['weight'];
        });

        return $streams;
    }

    /**
     * Close all streams
     */
    public function closeAllStreams(): void
    {
        foreach ($this->streams as $stream) {
            $stream->close();
        }
        $this->streams = [];
        $this->streamPriorities = [];
    }

    /**
     * Get statistics
     */
    public function getStats(): array
    {
        $openStreams = 0;
        $closedStreams = 0;

        foreach ($this->streams as $stream) {
            if ($stream->isOpen()) {
                $openStreams++;
            } else {
                $closedStreams++;
            }
        }

        return [
            'total_streams' => count($this->streams),
            'open_streams' => $openStreams,
            'closed_streams' => $closedStreams,
            'max_concurrent_streams' => $this->maxConcurrentStreams,
            'connection_window_size' => $this->connectionWindowSize,
            'next_stream_id' => $this->nextStreamId,
        ];
    }
}
