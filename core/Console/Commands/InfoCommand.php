<?php

declare(strict_types=1);

namespace HybridPHP\Core\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * System information command
 */
class InfoCommand extends Command
{
    protected static $defaultName = 'info';
    protected static $defaultDescription = 'Display system and framework information';

    protected function configure(): void
    {
        $this
            ->setDescription('Display system and framework information')
            ->setHelp('This command displays detailed information about the HybridPHP framework and system environment');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        try {
            $this->displayFrameworkInfo($io);
            $this->displaySystemInfo($io);
            $this->displayDependencyInfo($io);
            $this->displayConfigurationInfo($io);
            
            return Command::SUCCESS;
            
        } catch (\Throwable $e) {
            $io->error('Failed to retrieve system information: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    private function displayFrameworkInfo(SymfonyStyle $io): void
    {
        $io->title('HybridPHP Framework Information');
        
        $frameworkInfo = [
            'Framework' => 'HybridPHP',
            'Version' => '1.0.0',
            'Architecture' => 'Yii2 + Workerman + AMPHP',
            'Type' => 'Async/Multi-process Web Framework',
            'License' => 'MIT',
            'Documentation' => 'https://hybridphp.dev/docs'
        ];
        
        foreach ($frameworkInfo as $key => $value) {
            $io->text("<fg=cyan>{$key}:</> {$value}");
        }
    }

    private function displaySystemInfo(SymfonyStyle $io): void
    {
        $io->section('System Information');
        
        $systemInfo = [
            'PHP Version' => PHP_VERSION,
            'Operating System' => PHP_OS,
            'Architecture' => php_uname('m'),
            'Server API' => PHP_SAPI,
            'Memory Limit' => ini_get('memory_limit'),
            'Max Execution Time' => ini_get('max_execution_time') . 's',
            'Timezone' => date_default_timezone_get(),
            'Current Time' => date('Y-m-d H:i:s T')
        ];
        
        foreach ($systemInfo as $key => $value) {
            $io->text("<fg=green>{$key}:</> {$value}");
        }
    }

    private function displayDependencyInfo(SymfonyStyle $io): void
    {
        $io->section('Core Dependencies');
        
        $dependencies = $this->getInstalledPackages();
        
        $coreDeps = [
            'amphp/amp' => 'AMPHP Core',
            'amphp/http-server' => 'HTTP Server',
            'amphp/mysql' => 'MySQL Driver',
            'amphp/redis' => 'Redis Driver',
            'nikic/fast-route' => 'Router',
            'symfony/console' => 'CLI Framework',
            'monolog/monolog' => 'Logging',
            'vlucas/phpdotenv' => 'Environment'
        ];
        
        foreach ($coreDeps as $package => $description) {
            $version = $dependencies[$package] ?? 'Not installed';
            $status = $dependencies[$package] ? 'âœ? : 'â?;
            $io->text("{$status} <fg=yellow>{$description}</> ({$package}): {$version}");
        }
    }

    private function displayConfigurationInfo(SymfonyStyle $io): void
    {
        $io->section('Configuration Status');
        
        $configFiles = [
            'config/main.php' => 'Main Configuration',
            'config/database.php' => 'Database Configuration',
            'config/auth.php' => 'Authentication Configuration',
            'config/cache.php' => 'Cache Configuration',
            'config/logging.php' => 'Logging Configuration',
            '.env' => 'Environment Variables'
        ];
        
        foreach ($configFiles as $file => $description) {
            $exists = file_exists($file);
            $status = $exists ? 'âœ? : 'â?;
            $io->text("{$status} <fg=yellow>{$description}</> ({$file})");
        }
        
        // Check important directories
        $io->text('');
        $io->text('<fg=cyan>Directory Structure:</fg=cyan>');
        
        $directories = [
            'app/Controllers' => 'Controllers',
            'app/Models' => 'Models',
            'app/Middleware' => 'Middleware',
            'core' => 'Framework Core',
            'database/migrations' => 'Migrations',
            'storage/logs' => 'Log Storage',
            'storage/cache' => 'Cache Storage',
            'public' => 'Public Assets'
        ];
        
        foreach ($directories as $dir => $description) {
            $exists = is_dir($dir);
            $status = $exists ? 'âœ? : 'â?;
            $io->text("{$status} <fg=yellow>{$description}</> ({$dir})");
        }
    }

    private function getInstalledPackages(): array
    {
        $packages = [];
        
        $composerLock = 'composer.lock';
        if (file_exists($composerLock)) {
            $lockData = json_decode(file_get_contents($composerLock), true);
            
            if (isset($lockData['packages'])) {
                foreach ($lockData['packages'] as $package) {
                    $packages[$package['name']] = $package['version'];
                }
            }
        }
        
        return $packages;
    }
}