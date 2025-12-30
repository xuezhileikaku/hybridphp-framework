<?php

declare(strict_types=1);

namespace HybridPHP\Core\Server\Http2;

/**
 * HTTP/2 Stream Priority
 * 
 * Represents the priority of an HTTP/2 stream according to RFC 7540 Section 5.3.
 * 
 * Priority consists of:
 * - Weight: 1-256, determines relative bandwidth allocation among siblings
 * - Dependency: Stream ID this stream depends on (0 = root)
 * - Exclusive: If true, this stream becomes the sole dependency of parent
 */
class StreamPriority
{
    private int $streamId;
    private int $weight;
    private int $dependency;
    private bool $exclusive;
    private float $createdAt;
    private float $lastUpdated;

    /**
     * @param int $streamId Stream ID
     * @param int $weight Weight (1-256, default 16)
     * @param int $dependency Parent stream ID (0 = root)
     * @param bool $exclusive Exclusive dependency flag
     */
    public function __construct(
        int $streamId,
        int $weight = 16,
        int $dependency = 0,
        bool $exclusive = false
    ) {
        $this->streamId = $streamId;
        $this->weight = max(1, min(256, $weight));
        $this->dependency = $dependency;
        $this->exclusive = $exclusive;
        $this->createdAt = microtime(true);
        $this->lastUpdated = $this->createdAt;
    }

    /**
     * Get stream ID
     */
    public function getStreamId(): int
    {
        return $this->streamId;
    }

    /**
     * Get weight
     */
    public function getWeight(): int
    {
        return $this->weight;
    }

    /**
     * Set weight
     */
    public function setWeight(int $weight): void
    {
        $this->weight = max(1, min(256, $weight));
        $this->lastUpdated = microtime(true);
    }

    /**
     * Get dependency (parent stream ID)
     */
    public function getDependency(): int
    {
        return $this->dependency;
    }

    /**
     * Set dependency
     */
    public function setDependency(int $dependency): void
    {
        $this->dependency = $dependency;
        $this->lastUpdated = microtime(true);
    }

    /**
     * Check if exclusive
     */
    public function isExclusive(): bool
    {
        return $this->exclusive;
    }

    /**
     * Set exclusive flag
     */
    public function setExclusive(bool $exclusive): void
    {
        $this->exclusive = $exclusive;
        $this->lastUpdated = microtime(true);
    }

    /**
     * Get creation timestamp
     */
    public function getCreatedAt(): float
    {
        return $this->createdAt;
    }

    /**
     * Get last updated timestamp
     */
    public function getLastUpdated(): float
    {
        return $this->lastUpdated;
    }

    /**
     * Calculate relative priority score
     * Higher score = higher priority
     */
    public function getScore(): float
    {
        // Base score from weight (normalized to 0-1)
        $score = $this->weight / 256.0;
        
        // Slight boost for exclusive streams
        if ($this->exclusive) {
            $score *= 1.1;
        }
        
        return min(1.0, $score);
    }

    /**
     * Compare with another priority
     * Returns negative if this has lower priority, positive if higher
     */
    public function compareTo(StreamPriority $other): int
    {
        // First compare by dependency depth (lower depth = higher priority)
        // Then by weight (higher weight = higher priority)
        return $this->weight <=> $other->weight;
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'stream_id' => $this->streamId,
            'weight' => $this->weight,
            'dependency' => $this->dependency,
            'exclusive' => $this->exclusive,
            'score' => $this->getScore(),
            'created_at' => $this->createdAt,
            'last_updated' => $this->lastUpdated,
        ];
    }

    /**
     * Create from PRIORITY frame data
     */
    public static function fromFrame(int $streamId, array $frameData): self
    {
        return new self(
            $streamId,
            $frameData['weight'] ?? 16,
            $frameData['dependency'] ?? 0,
            $frameData['exclusive'] ?? false
        );
    }

    /**
     * Create default priority
     */
    public static function default(int $streamId): self
    {
        return new self($streamId, 16, 0, false);
    }
}
