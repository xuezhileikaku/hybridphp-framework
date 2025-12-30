<?php

declare(strict_types=1);

namespace HybridPHP\Core\Grpc\Discovery;

/**
 * Represents a service instance in service discovery
 */
class ServiceInstance
{
    public function __construct(
        public readonly string $id,
        public readonly string $serviceName,
        public readonly string $host,
        public readonly int $port,
        public readonly array $metadata = [],
        public readonly bool $healthy = true,
        public readonly int $weight = 100,
        public readonly ?string $zone = null,
    ) {}

    /**
     * Get the address string (host:port)
     */
    public function getAddress(): string
    {
        return sprintf('%s:%d', $this->host, $this->port);
    }

    /**
     * Create from array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? uniqid('instance_'),
            serviceName: $data['serviceName'] ?? $data['service'] ?? '',
            host: $data['host'] ?? 'localhost',
            port: $data['port'] ?? 50051,
            metadata: $data['metadata'] ?? [],
            healthy: $data['healthy'] ?? true,
            weight: $data['weight'] ?? 100,
            zone: $data['zone'] ?? null,
        );
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'serviceName' => $this->serviceName,
            'host' => $this->host,
            'port' => $this->port,
            'metadata' => $this->metadata,
            'healthy' => $this->healthy,
            'weight' => $this->weight,
            'zone' => $this->zone,
        ];
    }
}
