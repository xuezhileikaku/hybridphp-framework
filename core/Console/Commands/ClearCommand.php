<?php

declare(strict_types=1);

namespace HybridPHP\Core\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Clear caches and temporary files command
 */
class ClearCommand extends Command
{
    protected static $defaultName = 'clear';
    protected static $defaultDescription = 'Clear caches and temporary files';

    protected function configure(): void
    {
        $this
            ->setDescription('Clear caches and temporary files')
            ->setHelp('This command clears various caches and temporary files')
            ->addArgument(
                'type',
                InputArgument::OPTIONAL,
                'Type of cache to clear (cache, logs, sessions, all)',
                'all'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force clear without confirmation'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $type = $input->getArgument('type');
        $force = $input->getOption('force');
        
        try {
            if (!$force) {
                $confirm = $io->confirm("Are you sure you want to clear {$type}?", false);
                if (!$confirm) {
                    $io->text('Operation cancelled.');
                    return Command::SUCCESS;
                }
            }
            
            $io->title('Clearing HybridPHP Caches and Temporary Files');
            
            switch ($type) {
                case 'cache':
                    $this->clearCache($io);
                    break;
                case 'logs':
                    $this->clearLogs($io);
                    break;
                case 'sessions':
                    $this->clearSessions($io);
                    break;
                case 'all':
                default:
                    $this->clearCache($io);
                    $this->clearLogs($io);
                    $this->clearSessions($io);
                    $this->clearTempFiles($io);
                    break;
            }
            
            $io->success('Clear operation completed successfully!');
            
            return Command::SUCCESS;
            
        } catch (\Throwable $e) {
            $io->error('Clear command failed: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    private function clearCache(SymfonyStyle $io): void
    {
        $io->section('Clearing Cache');
        
        $cacheDirectories = [
            'storage/cache',
            'storage/cache/routes',
            'storage/cache/config',
            'storage/cache/views'
        ];
        
        foreach ($cacheDirectories as $dir) {
            if (is_dir($dir)) {
                $files = $this->getFilesInDirectory($dir);
                $count = 0;
                
                foreach ($files as $file) {
                    if (is_file($file) && basename($file) !== '.gitkeep') {
                        unlink($file);
                        $count++;
                    }
                }
                
                if ($count > 0) {
                    $io->text("�?Cleared {$count} cache files from {$dir}");
                } else {
                    $io->text("ℹ️  No cache files found in {$dir}");
                }
            } else {
                $io->text("ℹ️  Cache directory {$dir} does not exist");
            }
        }
    }

    private function clearLogs(SymfonyStyle $io): void
    {
        $io->section('Clearing Logs');
        
        $logDirectories = [
            'storage/logs'
        ];
        
        foreach ($logDirectories as $dir) {
            if (is_dir($dir)) {
                $files = $this->getFilesInDirectory($dir);
                $count = 0;
                
                foreach ($files as $file) {
                    if (is_file($file) && pathinfo($file, PATHINFO_EXTENSION) === 'log') {
                        unlink($file);
                        $count++;
                    }
                }
                
                if ($count > 0) {
                    $io->text("�?Cleared {$count} log files from {$dir}");
                } else {
                    $io->text("ℹ️  No log files found in {$dir}");
                }
            } else {
                $io->text("ℹ️  Log directory {$dir} does not exist");
            }
        }
    }

    private function clearSessions(SymfonyStyle $io): void
    {
        $io->section('Clearing Sessions');
        
        $sessionDirectories = [
            'storage/sessions'
        ];
        
        foreach ($sessionDirectories as $dir) {
            if (is_dir($dir)) {
                $files = $this->getFilesInDirectory($dir);
                $count = 0;
                
                foreach ($files as $file) {
                    if (is_file($file) && basename($file) !== '.gitkeep') {
                        unlink($file);
                        $count++;
                    }
                }
                
                if ($count > 0) {
                    $io->text("�?Cleared {$count} session files from {$dir}");
                } else {
                    $io->text("ℹ️  No session files found in {$dir}");
                }
            } else {
                $io->text("ℹ️  Session directory {$dir} does not exist");
            }
        }
    }

    private function clearTempFiles(SymfonyStyle $io): void
    {
        $io->section('Clearing Temporary Files');
        
        $tempFiles = [
            'storage/server.pid',
            'storage/server_start.php',
            'storage/schedule/tasks.json'
        ];
        
        $count = 0;
        foreach ($tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
                $count++;
                $io->text("�?Removed temporary file: {$file}");
            }
        }
        
        if ($count === 0) {
            $io->text("ℹ️  No temporary files found");
        }
        
        // Clear compiled class files
        $this->clearCompiledFiles($io);
    }

    private function clearCompiledFiles(SymfonyStyle $io): void
    {
        $compiledDirectories = [
            '.phpunit.cache',
            'vendor/composer/autoload_classmap.php',
            'vendor/composer/autoload_files.php',
            'vendor/composer/autoload_namespaces.php',
            'vendor/composer/autoload_psr4.php',
            'vendor/composer/autoload_real.php',
            'vendor/composer/autoload_static.php'
        ];
        
        $count = 0;
        foreach ($compiledDirectories as $path) {
            if (is_dir($path)) {
                $this->removeDirectory($path);
                $count++;
                $io->text("�?Removed compiled directory: {$path}");
            } elseif (is_file($path)) {
                unlink($path);
                $count++;
                $io->text("�?Removed compiled file: {$path}");
            }
        }
        
        if ($count > 0) {
            $io->text("ℹ️  Run 'composer dump-autoload' to regenerate autoloader");
        }
    }

    private function getFilesInDirectory(string $directory): array
    {
        $files = [];
        
        if (!is_dir($directory)) {
            return $files;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            $files[] = $file->getPathname();
        }
        
        return $files;
    }

    private function removeDirectory(string $directory): bool
    {
        if (!is_dir($directory)) {
            return false;
        }
        
        $files = array_diff(scandir($directory), ['.', '..']);
        
        foreach ($files as $file) {
            $path = $directory . DIRECTORY_SEPARATOR . $file;
            
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        
        return rmdir($directory);
    }
}