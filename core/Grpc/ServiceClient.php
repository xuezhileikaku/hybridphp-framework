<?php

declare(strict_types=1);

namespace HybridPHP\Core\Grpc;

use Amp\Future;

/**
 * Service-specific gRPC client wrapper
 */
class ServiceClient
{
    public function __construct(
        protected GrpcClient $client,
        protected string $serviceName,
    ) {}

    /**
     * Make a unary RPC call
     */
    public function call(string $method, mixed $request, array $metadata = []): Future
    {
        return $this->client->unary($this->serviceName, $method, $request, $metadata);
    }

    /**
     * Make a server streaming RPC call
     */
    public function serverStream(string $method, mixed $request, array $metadata = []): Future
    {
        return $this->client->serverStreaming($this->serviceName, $method, $request, $metadata);
    }

    /**
     * Make a client streaming RPC call
     */
    public function clientStream(string $method, iterable $requests, array $metadata = []): Future
    {
        return $this->client->clientStreaming($this->serviceName, $method, $requests, $metadata);
    }

    /**
     * Make a bidirectional streaming RPC call
     */
    public function bidiStream(string $method, iterable $requests, array $metadata = []): Future
    {
        return $this->client->bidiStreaming($this->serviceName, $method, $requests, $metadata);
    }

    /**
     * Get the service name
     */
    public function getServiceName(): string
    {
        return $this->serviceName;
    }
}
