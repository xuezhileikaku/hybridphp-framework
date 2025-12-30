<?php

declare(strict_types=1);

namespace HybridPHP\Core\Server\Http2;

/**
 * HTTP/2 Stream
 * 
 * Represents a single HTTP/2 stream within a connection.
 * Handles stream state, flow control, and data transfer.
 */
class Http2Stream
{
    // Stream states as defined in RFC 7540
    public const STATE_IDLE = 'idle';
    public const STATE_RESERVED_LOCAL = 'reserved_local';
    public const STATE_RESERVED_REMOTE = 'reserved_remote';
    public const STATE_OPEN = 'open';
    public const STATE_HALF_CLOSED_LOCAL = 'half_closed_local';
    public const STATE_HALF_CLOSED_REMOTE = 'half_closed_remote';
    public const STATE_CLOSED = 'closed';

    private int $id;
    private string $state;
    private int $windowSize;
    private int $initialWindowSize;
    private array $headers = [];
    private string $body = '';
    private bool $endStream = false;
    private float $createdAt;
    private ?float $closedAt = null;
    private int $bytesSent = 0;
    private int $bytesReceived = 0;

    public function __construct(int $id, int $initialWindowSize = 65535)
    {
        $this->id = $id;
        $this->state = self::STATE_IDLE;
        $this->windowSize = $initialWindowSize;
        $this->initialWindowSize = $initialWindowSize;
        $this->createdAt = microtime(true);
    }

    /**
     * Get stream ID
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Get stream state
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * Check if stream is open
     */
    public function isOpen(): bool
    {
        return in_array($this->state, [
            self::STATE_OPEN,
            self::STATE_HALF_CLOSED_LOCAL,
            self::STATE_HALF_CLOSED_REMOTE,
        ]);
    }

    /**
     * Check if stream can send data
     */
    public function canSend(): bool
    {
        return in_array($this->state, [
            self::STATE_OPEN,
            self::STATE_HALF_CLOSED_REMOTE,
        ]);
    }

    /**
     * Check if stream can receive data
     */
    public function canReceive(): bool
    {
        return in_array($this->state, [
            self::STATE_OPEN,
            self::STATE_HALF_CLOSED_LOCAL,
        ]);
    }

    /**
     * Open the stream
     */
    public function open(): void
    {
        if ($this->state === self::STATE_IDLE) {
            $this->state = self::STATE_OPEN;
        }
    }

    /**
     * Close the local side (half-close)
     */
    public function closeLocal(): void
    {
        if ($this->state === self::STATE_OPEN) {
            $this->state = self::STATE_HALF_CLOSED_LOCAL;
        } elseif ($this->state === self::STATE_HALF_CLOSED_REMOTE) {
            $this->close();
        }
    }

    /**
     * Close the remote side (half-close)
     */
    public function closeRemote(): void
    {
        if ($this->state === self::STATE_OPEN) {
            $this->state = self::STATE_HALF_CLOSED_REMOTE;
        } elseif ($this->state === self::STATE_HALF_CLOSED_LOCAL) {
            $this->close();
        }
    }

    /**
     * Fully close the stream
     */
    public function close(): void
    {
        $this->state = self::STATE_CLOSED;
        $this->closedAt = microtime(true);
    }

    /**
     * Reserve stream for server push (local)
     */
    public function reserveLocal(): void
    {
        if ($this->state === self::STATE_IDLE) {
            $this->state = self::STATE_RESERVED_LOCAL;
        }
    }

    /**
     * Reserve stream for server push (remote)
     */
    public function reserveRemote(): void
    {
        if ($this->state === self::STATE_IDLE) {
            $this->state = self::STATE_RESERVED_REMOTE;
        }
    }

    /**
     * Get window size
     */
    public function getWindowSize(): int
    {
        return $this->windowSize;
    }

    /**
     * Update window size
     */
    public function updateWindow(int $increment): void
    {
        $this->windowSize += $increment;
        
        // Ensure window doesn't exceed max value (2^31 - 1)
        if ($this->windowSize > 2147483647) {
            $this->windowSize = 2147483647;
        }
    }

    /**
     * Consume window for sending data
     */
    public function consumeWindow(int $size): bool
    {
        if ($size > $this->windowSize) {
            return false;
        }
        $this->windowSize -= $size;
        $this->bytesSent += $size;
        return true;
    }

    /**
     * Set headers
     */
    public function setHeaders(array $headers): void
    {
        $this->headers = $headers;
    }

    /**
     * Get headers
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Add header
     */
    public function addHeader(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }

    /**
     * Append body data
     */
    public function appendBody(string $data): void
    {
        $this->body .= $data;
        $this->bytesReceived += strlen($data);
    }

    /**
     * Get body
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Set end stream flag
     */
    public function setEndStream(bool $endStream): void
    {
        $this->endStream = $endStream;
        if ($endStream) {
            $this->closeRemote();
        }
    }

    /**
     * Check if end stream flag is set
     */
    public function isEndStream(): bool
    {
        return $this->endStream;
    }

    /**
     * Get stream duration
     */
    public function getDuration(): float
    {
        $endTime = $this->closedAt ?? microtime(true);
        return $endTime - $this->createdAt;
    }

    /**
     * Get bytes sent
     */
    public function getBytesSent(): int
    {
        return $this->bytesSent;
    }

    /**
     * Get bytes received
     */
    public function getBytesReceived(): int
    {
        return $this->bytesReceived;
    }

    /**
     * Get stream statistics
     */
    public function getStats(): array
    {
        return [
            'id' => $this->id,
            'state' => $this->state,
            'window_size' => $this->windowSize,
            'bytes_sent' => $this->bytesSent,
            'bytes_received' => $this->bytesReceived,
            'duration' => $this->getDuration(),
            'end_stream' => $this->endStream,
        ];
    }
}
