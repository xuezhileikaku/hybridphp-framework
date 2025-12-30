<?php

declare(strict_types=1);

namespace HybridPHP\Core\Grpc;

/**
 * Base interface for gRPC service implementations
 */
interface ServiceInterface
{
    /**
     * Get the fully qualified service name
     */
    public function getServiceName(): string;

    /**
     * Get the list of available methods
     *
     * @return array<string, array{type: string, request: string, response: string}>
     */
    public function getMethods(): array;
}
