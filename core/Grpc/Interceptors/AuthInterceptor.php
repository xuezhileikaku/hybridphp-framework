<?php

declare(strict_types=1);

namespace HybridPHP\Core\Grpc\Interceptors;

use HybridPHP\Core\Grpc\Context;
use HybridPHP\Core\Grpc\InterceptorInterface;
use HybridPHP\Core\Grpc\GrpcException;
use HybridPHP\Core\Grpc\Status;

/**
 * Authentication interceptor for gRPC calls
 */
class AuthInterceptor implements InterceptorInterface
{
    protected ?callable $validator;
    protected array $excludedMethods;

    public function __construct(?callable $validator = null, array $excludedMethods = [])
    {
        $this->validator = $validator;
        $this->excludedMethods = $excludedMethods;
    }

    public function intercept(mixed $request, Context $context, callable $next): mixed
    {
        // Check if method is excluded from auth
        $method = $context->getValue('method') ?? '';
        if (in_array($method, $this->excludedMethods)) {
            return $next($request, $context);
        }

        // Get auth token
        $token = $context->getAuthToken();

        if (!$token) {
            throw GrpcException::unauthenticated('Missing authentication token');
        }

        // Validate token
        if ($this->validator) {
            $user = ($this->validator)($token);
            
            if (!$user) {
                throw GrpcException::unauthenticated('Invalid authentication token');
            }

            // Add user to context
            $context->setValue('user', $user);
        }

        return $next($request, $context);
    }

    /**
     * Set the token validator
     */
    public function setValidator(callable $validator): self
    {
        $this->validator = $validator;
        return $this;
    }

    /**
     * Add methods to exclude from authentication
     */
    public function excludeMethods(array $methods): self
    {
        $this->excludedMethods = array_merge($this->excludedMethods, $methods);
        return $this;
    }
}
