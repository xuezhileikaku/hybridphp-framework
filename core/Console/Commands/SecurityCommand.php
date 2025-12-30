<?php

declare(strict_types=1);

namespace HybridPHP\Core\Console\Commands;

use HybridPHP\Core\Security\KeyManager;
use HybridPHP\Core\Security\AuditLogger;
use HybridPHP\Core\Security\EncryptionService;
use HybridPHP\Core\Security\TlsConfiguration;
use HybridPHP\Core\Container;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use function Amp\async;
use function Amp\await;

/**
 * Security management console command
 */
class SecurityCommand extends Command
{
    protected static $defaultName = 'security';
    protected static $defaultDescription = 'Manage security features (encryption, keys, audit logs)';

    private Container $container;

    public function __construct(Container $container)
    {
        parent::__construct();
        $this->container = $container;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'Action to perform (key:generate, key:rotate, key:list, audit:clean, tls:generate, tls:check)')
            ->addOption('key-id', null, InputOption::VALUE_REQUIRED, 'Key ID for key operations')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Number of days (for audit cleanup or cert validity)', 90)
            ->addOption('cert-path', null, InputOption::VALUE_REQUIRED, 'Certificate file path')
            ->addOption('key-path', null, InputOption::VALUE_REQUIRED, 'Private key file path')
            ->addOption('subject', null, InputOption::VALUE_REQUIRED, 'Certificate subject (JSON format)')
            ->setHelp('
Available actions:
  key:generate    Generate a new encryption key
  key:rotate      Rotate an existing encryption key
  key:list        List all encryption keys
  audit:clean     Clean old audit logs
  tls:generate    Generate self-signed TLS certificate
  tls:check       Check TLS configuration
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');

        try {
            switch ($action) {
                case 'key:generate':
                    return $this->generateKey($io, $input);
                    
                case 'key:rotate':
                    return $this->rotateKey($io, $input);
                    
                case 'key:list':
                    return $this->listKeys($io);
                    
                case 'audit:clean':
                    return $this->cleanAuditLogs($io, $input);
                    
                case 'tls:generate':
                    return $this->generateTlsCert($io, $input);
                    
                case 'tls:check':
                    return $this->checkTlsConfig($io);
                    
                default:
                    $io->error("Unknown action: {$action}");
                    return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $io->error("Error: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function generateKey(SymfonyStyle $io, InputInterface $input): int
    {
        $keyId = $input->getOption('key-id') ?? 'app_key_' . date('Y_m_d_H_i_s');
        
        $keyManager = $this->container->get(KeyManager::class);
        $encryption = $this->container->get(EncryptionService::class);
        
        $newKey = $encryption->generateKey();
        
        $keyManager->storeKey($keyId, $newKey, [
            'generated_by' => 'console_command',
            'generated_at' => date('Y-m-d H:i:s')
        ])->await();
        
        $io->success("Generated new encryption key: {$keyId}");
        $io->note("Key: {$newKey}");
        $io->warning("Store this key securely and update your APP_ENCRYPTION_KEY environment variable");
        
        return Command::SUCCESS;
    }

    private function rotateKey(SymfonyStyle $io, InputInterface $input): int
    {
        $keyId = $input->getOption('key-id');
        
        if (!$keyId) {
            $io->error("Key ID is required for rotation. Use --key-id option.");
            return Command::FAILURE;
        }
        
        $keyManager = $this->container->get(KeyManager::class);
        
        $newKey = $keyManager->rotateKey($keyId)->await();
        
        $io->success("Rotated encryption key: {$keyId}");
        $io->note("New key: {$newKey}");
        $io->warning("Update your APP_ENCRYPTION_KEY environment variable with the new key");
        
        return Command::SUCCESS;
    }

    private function listKeys(SymfonyStyle $io): int
    {
        $keyManager = $this->container->get(KeyManager::class);
        
        $keys = $keyManager->listKeys()->await();
        
        if (empty($keys)) {
            $io->info("No encryption keys found.");
            return Command::SUCCESS;
        }
        
        $rows = [];
        foreach ($keys as $key) {
            $rows[] = [
                $key['key_id'],
                $key['is_active'] ? 'Active' : 'Inactive',
                $key['created_at'],
                $key['rotated_at'] ?? 'Never',
                json_encode($key['metadata'])
            ];
        }
        
        $io->table(['Key ID', 'Status', 'Created', 'Rotated', 'Metadata'], $rows);
        
        return Command::SUCCESS;
    }

    private function cleanAuditLogs(SymfonyStyle $io, InputInterface $input): int
    {
        $days = (int)$input->getOption('days');
        
        $auditLogger = $this->container->get(AuditLogger::class);
        
        $deleted = $auditLogger->cleanOldLogs($days)->await();
        
        $io->success("Cleaned audit logs older than {$days} days. Deleted {$deleted} records.");
        
        return Command::SUCCESS;
    }

    private function generateTlsCert(SymfonyStyle $io, InputInterface $input): int
    {
        $certPath = $input->getOption('cert-path') ?? 'storage/ssl/server.crt';
        $keyPath = $input->getOption('key-path') ?? 'storage/ssl/server.key';
        $days = (int)$input->getOption('days');
        $subjectJson = $input->getOption('subject');
        
        $subject = [];
        if ($subjectJson) {
            $subject = json_decode($subjectJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $io->error("Invalid JSON in subject option");
                return Command::FAILURE;
            }
        }
        
        // Create directory if it doesn't exist
        $certDir = dirname($certPath);
        $keyDir = dirname($keyPath);
        
        if (!is_dir($certDir)) {
            mkdir($certDir, 0755, true);
        }
        if (!is_dir($keyDir)) {
            mkdir($keyDir, 0755, true);
        }
        
        $tlsConfig = $this->container->get(TlsConfiguration::class);
        
        $success = $tlsConfig->generateSelfSignedCert($certPath, $keyPath, $subject, $days);
        
        if ($success) {
            $io->success("Generated self-signed TLS certificate:");
            $io->listing([
                "Certificate: {$certPath}",
                "Private Key: {$keyPath}",
                "Valid for: {$days} days"
            ]);
            $io->warning("This is a self-signed certificate for development only. Use a proper CA-signed certificate in production.");
        } else {
            $io->error("Failed to generate TLS certificate");
            return Command::FAILURE;
        }
        
        return Command::SUCCESS;
    }

    private function checkTlsConfig(SymfonyStyle $io): int
    {
        $tlsConfig = $this->container->get(TlsConfiguration::class);
        
        $checks = $tlsConfig->checkTlsConfiguration();
        
        $io->section("TLS Configuration Check");
        
        $rows = [];
        foreach ($checks as $check => $status) {
            if ($check === 'all_passed') continue;
            
            $rows[] = [
                ucwords(str_replace('_', ' ', $check)),
                $status ? 'âœ?Pass' : 'âœ?Fail'
            ];
        }
        
        $io->table(['Check', 'Status'], $rows);
        
        if ($checks['all_passed']) {
            $io->success("All TLS configuration checks passed");
        } else {
            $io->error("Some TLS configuration checks failed");
            return Command::FAILURE;
        }
        
        // Show cipher suites
        $io->section("Supported Cipher Suites");
        $ciphers = $tlsConfig->getCipherSuites();
        $io->listing($ciphers);
        
        return Command::SUCCESS;
    }
}