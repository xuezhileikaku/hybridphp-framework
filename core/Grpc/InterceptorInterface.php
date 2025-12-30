<?php

declare(strict_types=1);

namespace HybridPHP\Core\Grpc;

use Amp\Future;

/**
 * Interface for gRPC interceptors (middleware)
 */
interface InterceptorInterface
{
    /**
     * Intercept a gRPC call
     *
     * @param mixed $request The request message
     * @param Context $context The call context
     * @param callable $next The next handler in the chain
     * @return Future|mixed The response
     */
    public function intercept(mixed $request, Context $context, callable $next): mixed;
}
