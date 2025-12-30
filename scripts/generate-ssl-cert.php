<?php

declare(strict_types=1);

/**
 * SSL Certificate Generator for HTTP/2 Development
 * 
 * Generates self-signed SSL certificates for local development.
 * Usage: php scripts/generate-ssl-cert.php [domain]
 */

$domain = $argv[1] ?? 'localhost';
$outputDir = __DIR__ . '/../storage/ssl';

// Ensure output directory exists
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
    echo "Created directory: {$outputDir}\n";
}

$certPath = "{$outputDir}/server.crt";
$keyPath = "{$outputDir}/server.key";

// Check if OpenSSL extension is available
if (!extension_loaded('openssl')) {
    echo "Error: OpenSSL extension is not loaded.\n";
    echo "Please enable the OpenSSL extension in your php.ini\n";
    exit(1);
}

echo "Generating SSL certificate for: {$domain}\n";
echo "Output directory: {$outputDir}\n\n";

// Generate private key
$privateKey = openssl_pkey_new([
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
]);

if (!$privateKey) {
    echo "Error: Failed to generate private key.\n";
    echo openssl_error_string() . "\n";
    exit(1);
}

// Certificate Distinguished Name
$dn = [
    'countryName' => 'CN',
    'stateOrProvinceName' => 'Shanghai',
    'localityName' => 'Shanghai',
    'organizationName' => 'HybridPHP Development',
    'organizationalUnitName' => 'Development',
    'commonName' => $domain,
    'emailAddress' => 'dev@hybridphp.local',
];

// Generate CSR
$csr = openssl_csr_new($dn, $privateKey, [
    'digest_alg' => 'sha256',
]);

if (!$csr) {
    echo "Error: Failed to generate CSR.\n";
    echo openssl_error_string() . "\n";
    exit(1);
}

// Self-sign the certificate (valid for 365 days)
$certificate = openssl_csr_sign($csr, null, $privateKey, 365, [
    'digest_alg' => 'sha256',
]);

if (!$certificate) {
    echo "Error: Failed to sign certificate.\n";
    echo openssl_error_string() . "\n";
    exit(1);
}

// Export private key
openssl_pkey_export($privateKey, $privateKeyPem);
file_put_contents($keyPath, $privateKeyPem);
chmod($keyPath, 0600);
echo "Private key saved to: {$keyPath}\n";

// Export certificate
openssl_x509_export($certificate, $certificatePem);
file_put_contents($certPath, $certificatePem);
chmod($certPath, 0644);
echo "Certificate saved to: {$certPath}\n";

// Display certificate info
$certInfo = openssl_x509_parse($certificate);
echo "\nCertificate Information:\n";
echo "  Subject: CN={$certInfo['subject']['CN']}\n";
echo "  Issuer: CN={$certInfo['issuer']['CN']}\n";
echo "  Valid From: " . date('Y-m-d H:i:s', $certInfo['validFrom_time_t']) . "\n";
echo "  Valid To: " . date('Y-m-d H:i:s', $certInfo['validTo_time_t']) . "\n";

echo "\n✅ SSL certificate generated successfully!\n";
echo "\nTo use HTTP/2 server, update your .env file:\n";
echo "  TLS_CERT_PATH=storage/ssl/server.crt\n";
echo "  TLS_KEY_PATH=storage/ssl/server.key\n";
echo "  TLS_ALLOW_SELF_SIGNED=true\n";
echo "\nNote: This is a self-signed certificate for development only.\n";
echo "For production, use certificates from a trusted CA.\n";
