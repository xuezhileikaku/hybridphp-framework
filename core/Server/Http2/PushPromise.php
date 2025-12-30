<?php

declare(strict_types=1);

namespace HybridPHP\Core\Server\Http2;

/**
 * HTTP/2 Push Promise
 * 
 * Represents a server push promise for HTTP/2 connections.
 * Contains the promised request headers and resource information.
 * 
 * @see RFC 7540 Section 8.2 - Server Push
 */
class PushPromise
{
    // Push promise states
    public const STATE_PENDING = 'pending';
    public const STATE_SENT = 'sent';
    public const STATE_CANCELLED = 'cancelled';
    public const STATE_COMPLETED = 'completed';
    public const STATE_FAILED = 'failed';

    private string $path;
    private string $method;
    private string $scheme;
    private string $authority;
    private string $resourceType;
    private array $headers;
    private string $state;
    private ?int $promisedStreamId;
    private ?int $parentStreamId;
    private float $createdAt;
    private ?float $sentAt;
    private ?float $completedAt;
    private int $priority;
    private ?string $body;
    private array $metadata;

    public function __construct(
        string $path,
        string $resourceType = 'fetch',
        array $options = []
    ) {
        $this->path = $path;
        $this->resourceType = $resourceType;
        $this->method = $options['method'] ?? 'GET';
        $this->scheme = $options['scheme'] ?? 'https';
        $this->authority = $options['authority'] ?? '';
        $this->headers = $options['headers'] ?? [];
        $this->state = self::STATE_PENDING;
        $this->promisedStreamId = null;
        $this->parentStreamId = $options['parent_stream_id'] ?? null;
        $this->createdAt = microtime(true);
        $this->sentAt = null;
        $this->completedAt = null;
        $this->priority = $options['priority'] ?? 16;
        $this->body = null;
        $this->metadata = $options['metadata'] ?? [];
    }

    /**
     * Get the resource path
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Get the HTTP method
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Get the scheme (http/https)
     */
    public function getScheme(): string
    {
        return $this->scheme;
    }

    /**
     * Get the authority (host:port)
     */
    public function getAuthority(): string
    {
        return $this->authority;
    }

    /**
     * Set the authority
     */
    public function setAuthority(string $authority): self
    {
        $this->authority = $authority;
        return $this;
    }

    /**
     * Get the resource type (style, script, font, image, etc.)
     */
    public function getResourceType(): string
    {
        return $this->resourceType;
    }

    /**
     * Get additional headers
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Add a header
     */
    public function addHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Get the current state
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * Check if push promise is pending
     */
    public function isPending(): bool
    {
        return $this->state === self::STATE_PENDING;
    }

    /**
     * Check if push promise was sent
     */
    public function isSent(): bool
    {
        return $this->state === self::STATE_SENT;
    }

    /**
     * Check if push promise is completed
     */
    public function isCompleted(): bool
    {
        return $this->state === self::STATE_COMPLETED;
    }

    /**
     * Mark as sent
     */
    public function markSent(int $streamId): self
    {
        $this->state = self::STATE_SENT;
        $this->promisedStreamId = $streamId;
        $this->sentAt = microtime(true);
        return $this;
    }

    /**
     * Mark as completed
     */
    public function markCompleted(): self
    {
        $this->state = self::STATE_COMPLETED;
        $this->completedAt = microtime(true);
        return $this;
    }

    /**
     * Mark as cancelled
     */
    public function markCancelled(): self
    {
        $this->state = self::STATE_CANCELLED;
        $this->completedAt = microtime(true);
        return $this;
    }

    /**
     * Mark as failed
     */
    public function markFailed(): self
    {
        $this->state = self::STATE_FAILED;
        $this->completedAt = microtime(true);
        return $this;
    }

    /**
     * Get the promised stream ID
     */
    public function getPromisedStreamId(): ?int
    {
        return $this->promisedStreamId;
    }

    /**
     * Get the parent stream ID
     */
    public function getParentStreamId(): ?int
    {
        return $this->parentStreamId;
    }

    /**
     * Set the parent stream ID
     */
    public function setParentStreamId(int $streamId): self
    {
        $this->parentStreamId = $streamId;
        return $this;
    }

    /**
     * Get priority weight (1-256)
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Set priority weight
     */
    public function setPriority(int $priority): self
    {
        $this->priority = max(1, min(256, $priority));
        return $this;
    }

    /**
     * Set the response body for the pushed resource
     */
    public function setBody(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    /**
     * Get the response body
     */
    public function getBody(): ?string
    {
        return $this->body;
    }

    /**
     * Set metadata
     */
    public function setMetadata(string $key, mixed $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    /**
     * Get metadata
     */
    public function getMetadata(string $key = null): mixed
    {
        if ($key === null) {
            return $this->metadata;
        }
        return $this->metadata[$key] ?? null;
    }

    /**
     * Get the pseudo-headers for PUSH_PROMISE frame
     */
    public function getPseudoHeaders(): array
    {
        return [
            ':method' => $this->method,
            ':path' => $this->path,
            ':scheme' => $this->scheme,
            ':authority' => $this->authority,
        ];
    }

    /**
     * Get all headers including pseudo-headers
     */
    public function getAllHeaders(): array
    {
        return array_merge($this->getPseudoHeaders(), $this->headers);
    }

    /**
     * Create Link header value for this push promise
     */
    public function toLinkHeader(): string
    {
        $header = "<{$this->path}>; rel=preload; as={$this->resourceType}";
        
        // Add crossorigin for fonts
        if ($this->resourceType === 'font') {
            $crossorigin = $this->headers['crossorigin'] ?? 'anonymous';
            $header .= "; crossorigin={$crossorigin}";
        } elseif (isset($this->headers['crossorigin'])) {
            $header .= "; crossorigin={$this->headers['crossorigin']}";
        }
        
        return $header;
    }

    /**
     * Get duration (time from creation to completion)
     */
    public function getDuration(): ?float
    {
        if ($this->completedAt === null) {
            return null;
        }
        return $this->completedAt - $this->createdAt;
    }

    /**
     * Get latency (time from sent to completion)
     */
    public function getLatency(): ?float
    {
        if ($this->sentAt === null || $this->completedAt === null) {
            return null;
        }
        return $this->completedAt - $this->sentAt;
    }

    /**
     * Get statistics
     */
    public function getStats(): array
    {
        return [
            'path' => $this->path,
            'type' => $this->resourceType,
            'state' => $this->state,
            'stream_id' => $this->promisedStreamId,
            'parent_stream_id' => $this->parentStreamId,
            'priority' => $this->priority,
            'duration' => $this->getDuration(),
            'latency' => $this->getLatency(),
            'created_at' => $this->createdAt,
            'sent_at' => $this->sentAt,
            'completed_at' => $this->completedAt,
        ];
    }

    /**
     * Create from resource array
     */
    public static function fromResource(array $resource, string $authority = ''): self
    {
        return new self(
            $resource['path'],
            $resource['type'] ?? 'fetch',
            [
                'authority' => $authority,
                'headers' => array_filter([
                    'crossorigin' => $resource['crossorigin'] ?? null,
                ]),
                'priority' => $resource['priority'] ?? 16,
                'metadata' => $resource['metadata'] ?? [],
            ]
        );
    }
}
