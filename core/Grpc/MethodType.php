<?php

declare(strict_types=1);

namespace HybridPHP\Core\Grpc;

/**
 * gRPC method types
 */
enum MethodType: string
{
    case UNARY = 'unary';
    case SERVER_STREAMING = 'server_streaming';
    case CLIENT_STREAMING = 'client_streaming';
    case BIDI_STREAMING = 'bidi_streaming';
}
