<?php

declare(strict_types=1);

namespace HybridPHP\Core\Server\Http2;

/**
 * HTTP/2 Server Configuration
 * 
 * Manages all HTTP/2 related configuration including:
 * - TLS/SSL settings
 * - HTTP/2 specific settings
 * - Server Push configuration
 * - HPACK settings
 */
class Http2Config
{
    private string $host;
    private int $port;
    private bool $http2Enabled;
    private bool $serverPushEnabled;
    private int $maxConcurrentStreams;
    private int $initialWindowSize;
    private int $maxFrameSize;
    private int $maxHeaderListSize;
    private int $headerTableSize;
    private bool $hpackEnabled;
    private bool $hpackHuffmanEnabled;
    private string $certPath;
    private string $keyPath;
    private ?string $caPath;
    private string $minTlsVersion;
    private string $maxTlsVersion;
    private array $cipherSuites;
    private bool $verifyPeer;
    private bool $allowSelfSigned;
    private int $connectionTimeout;
    private int $requestTimeout;
    private int $bodySizeLimit;
    private bool $enableCompression;
    private array $pushRules;
    private array $securityHeaders;

    public function __construct(array $config = [])
    {
        // Server settings
        $this->host = $config['host'] ?? '0.0.0.0';
        $this->port = $config['port'] ?? 8443;
        
        // HTTP/2 settings
        $this->http2Enabled = $config['enable_http2'] ?? true;
        $this->serverPushEnabled = $config['enable_server_push'] ?? true;
        $this->maxConcurrentStreams = $config['max_concurrent_streams'] ?? 100;
        $this->initialWindowSize = $config['initial_window_size'] ?? 65535;
        $this->maxFrameSize = $config['max_frame_size'] ?? 16384;
        $this->maxHeaderListSize = $config['max_header_list_size'] ?? 8192;
        $this->headerTableSize = $config['header_table_size'] ?? 4096;
        $this->hpackEnabled = $config['hpack_enabled'] ?? true;
        $this->hpackHuffmanEnabled = $config['hpack_huffman_enabled'] ?? true;
        
        // TLS settings
        $this->certPath = $config['cert_path'] ?? 'storage/ssl/server.crt';
        $this->keyPath = $config['key_path'] ?? 'storage/ssl/server.key';
        $this->caPath = $config['ca_path'] ?? null;
        $this->minTlsVersion = $config['min_tls_version'] ?? 'TLSv1.2';
        $this->maxTlsVersion = $config['max_tls_version'] ?? 'TLSv1.3';
        $this->verifyPeer = $config['verify_peer'] ?? true;
        $this->allowSelfSigned = $config['allow_self_signed'] ?? false;
        
        // Cipher suites (HTTP/2 compatible)
        $this->cipherSuites = $config['cipher_suites'] ?? [
            'ECDHE-ECDSA-AES256-GCM-SHA384',
            'ECDHE-RSA-AES256-GCM-SHA384',
            'ECDHE-ECDSA-CHACHA20-POLY1305',
            'ECDHE-RSA-CHACHA20-POLY1305',
            'ECDHE-ECDSA-AES128-GCM-SHA256',
            'ECDHE-RSA-AES128-GCM-SHA256',
        ];
        
        // Connection settings
        $this->connectionTimeout = $config['connection_timeout'] ?? 30;
        $this->requestTimeout = $config['request_timeout'] ?? 30;
        $this->bodySizeLimit = $config['body_size_limit'] ?? 128 * 1024 * 1024;
        $this->enableCompression = $config['enable_compression'] ?? true;
        
        // Server Push rules
        $this->pushRules = $config['push_rules'] ?? [];
        
        // Security headers
        $this->securityHeaders = $config['security_headers'] ?? [
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains; preload',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
        ];
    }


    // Getters
    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function isHttp2Enabled(): bool
    {
        return $this->http2Enabled;
    }

    public function isServerPushEnabled(): bool
    {
        return $this->serverPushEnabled;
    }

    public function getMaxConcurrentStreams(): int
    {
        return $this->maxConcurrentStreams;
    }

    public function getInitialWindowSize(): int
    {
        return $this->initialWindowSize;
    }

    public function getMaxFrameSize(): int
    {
        return $this->maxFrameSize;
    }

    public function getMaxHeaderListSize(): int
    {
        return $this->maxHeaderListSize;
    }

    public function getHeaderTableSize(): int
    {
        return $this->headerTableSize;
    }

    public function isHpackEnabled(): bool
    {
        return $this->hpackEnabled;
    }

    public function isHpackHuffmanEnabled(): bool
    {
        return $this->hpackHuffmanEnabled;
    }

    public function getCertPath(): string
    {
        return $this->certPath;
    }

    public function getKeyPath(): string
    {
        return $this->keyPath;
    }

    public function getCaPath(): ?string
    {
        return $this->caPath;
    }

    public function getMinTlsVersion(): string
    {
        return $this->minTlsVersion;
    }

    public function getMaxTlsVersion(): string
    {
        return $this->maxTlsVersion;
    }

    public function getCipherSuites(): array
    {
        return $this->cipherSuites;
    }

    public function shouldVerifyPeer(): bool
    {
        return $this->verifyPeer;
    }

    public function allowsSelfSigned(): bool
    {
        return $this->allowSelfSigned;
    }

    public function getConnectionTimeout(): int
    {
        return $this->connectionTimeout;
    }

    public function getRequestTimeout(): int
    {
        return $this->requestTimeout;
    }

    public function getBodySizeLimit(): int
    {
        return $this->bodySizeLimit;
    }

    public function isCompressionEnabled(): bool
    {
        return $this->enableCompression;
    }

    public function getPushRules(): array
    {
        return $this->pushRules;
    }

    public function getSecurityHeaders(): array
    {
        return $this->securityHeaders;
    }

    /**
     * Convert configuration to array
     */
    public function toArray(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'enable_http2' => $this->http2Enabled,
            'enable_server_push' => $this->serverPushEnabled,
            'max_concurrent_streams' => $this->maxConcurrentStreams,
            'initial_window_size' => $this->initialWindowSize,
            'max_frame_size' => $this->maxFrameSize,
            'max_header_list_size' => $this->maxHeaderListSize,
            'header_table_size' => $this->headerTableSize,
            'hpack_enabled' => $this->hpackEnabled,
            'hpack_huffman_enabled' => $this->hpackHuffmanEnabled,
            'cert_path' => $this->certPath,
            'key_path' => $this->keyPath,
            'ca_path' => $this->caPath,
            'min_tls_version' => $this->minTlsVersion,
            'max_tls_version' => $this->maxTlsVersion,
            'cipher_suites' => $this->cipherSuites,
            'verify_peer' => $this->verifyPeer,
            'allow_self_signed' => $this->allowSelfSigned,
            'connection_timeout' => $this->connectionTimeout,
            'request_timeout' => $this->requestTimeout,
            'body_size_limit' => $this->bodySizeLimit,
            'enable_compression' => $this->enableCompression,
            'push_rules' => $this->pushRules,
            'security_headers' => $this->securityHeaders,
        ];
    }

    /**
     * Create from environment variables
     */
    public static function fromEnv(): self
    {
        return new self([
            'host' => $_ENV['HTTP2_HOST'] ?? '0.0.0.0',
            'port' => (int) ($_ENV['HTTP2_PORT'] ?? 8443),
            'enable_http2' => filter_var($_ENV['HTTP2_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'enable_server_push' => filter_var($_ENV['HTTP2_SERVER_PUSH'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'max_concurrent_streams' => (int) ($_ENV['HTTP2_MAX_STREAMS'] ?? 100),
            'cert_path' => $_ENV['TLS_CERT_PATH'] ?? 'storage/ssl/server.crt',
            'key_path' => $_ENV['TLS_KEY_PATH'] ?? 'storage/ssl/server.key',
            'ca_path' => $_ENV['TLS_CA_PATH'] ?? null,
            'min_tls_version' => $_ENV['TLS_MIN_VERSION'] ?? 'TLSv1.2',
            'allow_self_signed' => filter_var($_ENV['TLS_ALLOW_SELF_SIGNED'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    /**
     * Validate configuration
     */
    public function validate(): array
    {
        $errors = [];

        if ($this->http2Enabled) {
            if (!file_exists($this->certPath)) {
                $errors[] = "SSL certificate not found: {$this->certPath}";
            }
            
            if (!file_exists($this->keyPath)) {
                $errors[] = "SSL private key not found: {$this->keyPath}";
            }
            
            if ($this->caPath && !file_exists($this->caPath)) {
                $errors[] = "CA certificate not found: {$this->caPath}";
            }
        }

        if ($this->maxConcurrentStreams < 1 || $this->maxConcurrentStreams > 1000) {
            $errors[] = "max_concurrent_streams must be between 1 and 1000";
        }

        if ($this->initialWindowSize < 1 || $this->initialWindowSize > 2147483647) {
            $errors[] = "initial_window_size must be between 1 and 2147483647";
        }

        if ($this->maxFrameSize < 16384 || $this->maxFrameSize > 16777215) {
            $errors[] = "max_frame_size must be between 16384 and 16777215";
        }

        return $errors;
    }
}
