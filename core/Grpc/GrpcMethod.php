<?php

declare(strict_types=1);

namespace HybridPHP\Core\Grpc;

use Attribute;

/**
 * Attribute to mark gRPC methods with their type
 */
#[Attribute(Attribute::TARGET_METHOD)]
class GrpcMethod
{
    public function __construct(
        public MethodType $type = MethodType::UNARY,
        public ?string $requestClass = null,
        public ?string $responseClass = null,
    ) {}
}
