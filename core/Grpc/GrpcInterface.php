<?php

declare(strict_types=1);

namespace HybridPHP\Core\Grpc;

use Amp\Future;

/**
 * gRPC service interface for async operations
 */
interface GrpcInterface
{
    /**
     * Handle a unary RPC call
     *
     * @param string $service Service name
     * @param string $method Method name
     * @param string $requestData Serialized protobuf request
     * @param array $metadata Request metadata
     * @return Future<string> Resolves to serialized protobuf response
     */
    public function handleUnary(
        string $service,
        string $method,
        string $requestData,
        array $metadata = []
    ): Future;

    /**
     * Handle a server streaming RPC call
     *
     * @param string $service Service name
     * @param string $method Method name
     * @param string $requestData Serialized protobuf request
     * @param array $metadata Request metadata
     * @return Future<iterable> Resolves to iterable of serialized responses
     */
    public function handleServerStreaming(
        string $service,
        string $method,
        string $requestData,
        array $metadata = []
    ): Future;

    /**
     * Handle a client streaming RPC call
     *
     * @param string $service Service name
     * @param string $method Method name
     * @param iterable $requestStream Stream of serialized protobuf requests
     * @param array $metadata Request metadata
     * @return Future<string> Resolves to serialized protobuf response
     */
    public function handleClientStreaming(
        string $service,
        string $method,
        iterable $requestStream,
        array $metadata = []
    ): Future;

    /**
     * Handle a bidirectional streaming RPC call
     *
     * @param string $service Service name
     * @param string $method Method name
     * @param iterable $requestStream Stream of serialized protobuf requests
     * @param array $metadata Request metadata
     * @return Future<iterable> Resolves to iterable of serialized responses
     */
    public function handleBidiStreaming(
        string $service,
        string $method,
        iterable $requestStream,
        array $metadata = []
    ): Future;

    /**
     * Register a service implementation
     *
     * @param string $serviceName Fully qualified service name
     * @param object $implementation Service implementation instance
     */
    public function registerService(string $serviceName, object $implementation): void;

    /**
     * Check if a service is registered
     */
    public function hasService(string $serviceName): bool;

    /**
     * Get all registered services
     *
     * @return array<string, object>
     */
    public function getServices(): array;
}
