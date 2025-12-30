<?php

declare(strict_types=1);

namespace HybridPHP\Core\Security;

/**
 * TLS/SSL configuration service for secure transmission
 */
class TlsConfiguration
{
    private array $defaultOptions = [];
    private array $cipherSuites = [];

    public function __construct()
    {
        $this->initializeDefaults();
    }

    /**
     * Initialize default TLS options
     */
    private function initializeDefaults(): void
    {
        $this->defaultOptions = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'disable_compression' => true,
            'SNI_enabled' => true,
            'capture_peer_cert' => true,
            'capture_peer_cert_chain' => true,
            'peer_fingerprint' => null,
            'security_level' => 2,
        ];

        // Secure cipher suites (prioritize forward secrecy)
        $this->cipherSuites = [
            'ECDHE-ECDSA-AES256-GCM-SHA384',
            'ECDHE-RSA-AES256-GCM-SHA384',
            'ECDHE-ECDSA-CHACHA20-POLY1305',
            'ECDHE-RSA-CHACHA20-POLY1305',
            'ECDHE-ECDSA-AES128-GCM-SHA256',
            'ECDHE-RSA-AES128-GCM-SHA256',
            'ECDHE-ECDSA-AES256-SHA384',
            'ECDHE-RSA-AES256-SHA384',
            'ECDHE-ECDSA-AES128-SHA256',
            'ECDHE-RSA-AES128-SHA256',
        ];
    }

    /**
     * Get TLS context options for client connections
     */
    public function getClientContextOptions(array $customOptions = []): array
    {
        $options = array_merge($this->defaultOptions, [
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            'ciphers' => implode(':', $this->cipherSuites),
        ], $customOptions);

        return ['ssl' => $options];
    }

    /**
     * Get TLS context options for server connections
     */
    public function getServerContextOptions(
        string $certPath,
        string $keyPath,
        ?string $caPath = null,
        array $customOptions = []
    ): array {
        $options = array_merge($this->defaultOptions, [
            'local_cert' => $certPath,
            'local_pk' => $keyPath,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER,
            'ciphers' => implode(':', $this->cipherSuites),
            'honor_cipher_order' => true,
            'single_dh_use' => true,
            'single_ecdh_use' => true,
        ], $customOptions);

        if ($caPath !== null) {
            $options['cafile'] = $caPath;
        }

        return ['ssl' => $options];
    }

    /**
     * Get AMPHP HTTP server TLS options
     */
    public function getAmphpServerOptions(
        string $certPath,
        string $keyPath,
        ?string $caPath = null
    ): array {
        return [
            'tls' => [
                'local_cert' => $certPath,
                'local_pk' => $keyPath,
                'verify_peer' => false, // For server, we don't verify client certs by default
                'allow_self_signed' => false,
                'disable_compression' => true,
                'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER,
                'ciphers' => implode(':', $this->cipherSuites),
                'honor_cipher_order' => true,
                'single_dh_use' => true,
                'single_ecdh_use' => true,
                'security_level' => 2,
            ]
        ];
    }

    /**
     * Validate certificate
     */
    public function validateCertificate(string $certPath): array
    {
        if (!file_exists($certPath)) {
            return ['valid' => false, 'error' => 'Certificate file not found'];
        }

        $cert = openssl_x509_read(file_get_contents($certPath));
        if ($cert === false) {
            return ['valid' => false, 'error' => 'Invalid certificate format'];
        }

        $info = openssl_x509_parse($cert);
        $now = time();

        $result = [
            'valid' => true,
            'subject' => $info['subject'] ?? [],
            'issuer' => $info['issuer'] ?? [],
            'valid_from' => $info['validFrom_time_t'] ?? 0,
            'valid_to' => $info['validTo_time_t'] ?? 0,
            'serial_number' => $info['serialNumber'] ?? '',
            'signature_algorithm' => $info['signatureTypeSN'] ?? '',
        ];

        // Check expiration
        if ($info['validTo_time_t'] < $now) {
            $result['expired'] = true;
            $result['warning'] = 'Certificate has expired';
        } elseif ($info['validTo_time_t'] < ($now + 2592000)) { // 30 days
            $result['expires_soon'] = true;
            $result['warning'] = 'Certificate expires within 30 days';
        }

        // Check if not yet valid
        if ($info['validFrom_time_t'] > $now) {
            $result['not_yet_valid'] = true;
            $result['warning'] = 'Certificate is not yet valid';
        }

        openssl_x509_free($cert);
        return $result;
    }

    /**
     * Generate self-signed certificate for development
     */
    public function generateSelfSignedCert(
        string $certPath,
        string $keyPath,
        array $subject = [],
        int $days = 365
    ): bool {
        $defaultSubject = [
            'countryName' => 'US',
            'stateOrProvinceName' => 'State',
            'localityName' => 'City',
            'organizationName' => 'HybridPHP Framework',
            'organizationalUnitName' => 'Development',
            'commonName' => 'localhost',
            'emailAddress' => 'dev@hybridphp.local'
        ];

        $subject = array_merge($defaultSubject, $subject);

        // Generate private key
        $privateKey = openssl_pkey_new([
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($privateKey === false) {
            return false;
        }

        // Generate certificate signing request
        $csr = openssl_csr_new($subject, $privateKey, [
            'digest_alg' => 'sha256',
            'req_extensions' => 'v3_req',
            'x509_extensions' => 'v3_ca',
        ]);

        if ($csr === false) {
            return false;
        }

        // Generate self-signed certificate
        $cert = openssl_csr_sign($csr, null, $privateKey, $days, [
            'digest_alg' => 'sha256',
            'x509_extensions' => 'v3_ca',
        ]);

        if ($cert === false) {
            return false;
        }

        // Export certificate and private key
        $certOut = '';
        $keyOut = '';
        
        if (!openssl_x509_export($cert, $certOut) || 
            !openssl_pkey_export($privateKey, $keyOut)) {
            return false;
        }

        // Save to files
        $certSaved = file_put_contents($certPath, $certOut) !== false;
        $keySaved = file_put_contents($keyPath, $keyOut) !== false;

        // Set proper permissions
        if ($keySaved) {
            chmod($keyPath, 0600);
        }
        if ($certSaved) {
            chmod($certPath, 0644);
        }

        // Clean up
        openssl_x509_free($cert);
        openssl_pkey_free($privateKey);

        return $certSaved && $keySaved;
    }

    /**
     * Get security headers for HTTPS
     */
    public function getSecurityHeaders(): array
    {
        return [
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains; preload',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-XSS-Protection' => '1; mode=block',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'",
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
        ];
    }

    /**
     * Check if TLS is properly configured
     */
    public function checkTlsConfiguration(): array
    {
        $checks = [
            'openssl_loaded' => extension_loaded('openssl'),
            'tls_1_2_support' => defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT'),
            'tls_1_3_support' => defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT'),
            'cipher_support' => !empty($this->cipherSuites),
        ];

        $checks['all_passed'] = !in_array(false, $checks, true);

        return $checks;
    }

    /**
     * Add custom cipher suite
     */
    public function addCipherSuite(string $cipher): void
    {
        if (!in_array($cipher, $this->cipherSuites)) {
            $this->cipherSuites[] = $cipher;
        }
    }

    /**
     * Set custom TLS option
     */
    public function setOption(string $key, mixed $value): void
    {
        $this->defaultOptions[$key] = $value;
    }

    /**
     * Get current cipher suites
     */
    public function getCipherSuites(): array
    {
        return $this->cipherSuites;
    }

    /**
     * Get current TLS options
     */
    public function getOptions(): array
    {
        return $this->defaultOptions;
    }
}