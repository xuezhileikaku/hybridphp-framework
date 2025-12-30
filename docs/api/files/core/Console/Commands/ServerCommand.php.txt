<?php

declare(strict_types=1);

namespace HybridPHP\Core\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use HybridPHP\Core\ConfigManager;

/**
 * Server management command
 */
class ServerCommand extends Command
{
    protected static $defaultName = 'server';
    protected static $defaultDescription = 'Manage the HybridPHP server';

    protected function configure(): void
    {
        $this
            ->setDescription('Manage the HybridPHP server (start, stop, restart, status)')
            ->setHelp('This command allows you to manage the HybridPHP server')
            ->addArgument(
                'action',
                InputArgument::REQUIRED,
                'Action to perform (start, stop, restart, status, reload)'
            )
            ->addOption(
                'host',
                null,
                InputOption::VALUE_REQUIRED,
                'Server host',
                '127.0.0.1'
            )
            ->addOption(
                'port',
                'p',
                InputOption::VALUE_REQUIRED,
                'Server port',
                '8080'
            )
            ->addOption(
                'workers',
                'w',
                InputOption::VALUE_REQUIRED,
                'Number of worker processes',
                '4'
            )
            ->addOption(
                'daemon',
                'd',
                InputOption::VALUE_NONE,
                'Run server in daemon mode'
            )
            ->addOption(
                'pidfile',
                null,
                InputOption::VALUE_REQUIRED,
                'PID file location',
                'storage/server.pid'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');
        
        try {
            switch ($action) {
                case 'start':
                    return $this->startServer($input, $io);
                case 'stop':
                    return $this->stopServer($input, $io);
                case 'restart':
                    return $this->restartServer($input, $io);
                case 'status':
                    return $this->serverStatus($input, $io);
                case 'reload':
                    return $this->reloadServer($input, $io);
                default:
                    $io->error("Unknown action: {$action}");
                    $io->text("Available actions: start, stop, restart, status, reload");
                    return Command::FAILURE;
            }
        } catch (\Throwable $e) {
            $io->error('Server command failed: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    private function startServer(InputInterface $input, SymfonyStyle $io): int
    {
        $host = $input->getOption('host');
        $port = $input->getOption('port');
        $workers = (int) $input->getOption('workers');
        $daemon = $input->getOption('daemon');
        $pidfile = $input->getOption('pidfile');
        
        // Check if server is already running
        if ($this->isServerRunning($pidfile)) {
            $io->warning('Server is already running');
            return Command::SUCCESS;
        }
        
        $io->text("Starting HybridPHP server on {$host}:{$port} with {$workers} workers...");
        
        // Create server start script
        $serverScript = $this->createServerScript($host, $port, $workers, $daemon);
        
        if ($daemon) {
            // Start in daemon mode
            $command = "php {$serverScript} > /dev/null 2>&1 & echo \$! > {$pidfile}";
            exec($command);
            
            // Wait a moment and check if server started
            sleep(1);
            if ($this->isServerRunning($pidfile)) {
                $io->success("Server started in daemon mode (PID: " . trim(file_get_contents($pidfile)) . ")");
            } else {
                $io->error('Failed to start server in daemon mode');
                return Command::FAILURE;
            }
        } else {
            // Start in foreground
            $io->success("Server starting on http://{$host}:{$port}");
            $io->text('Press Ctrl+C to stop the server');
            
            // Execute the server script
            passthru("php {$serverScript}");
        }
        
        return Command::SUCCESS;
    }

    private function stopServer(InputInterface $input, SymfonyStyle $io): int
    {
        $pidfile = $input->getOption('pidfile');
        
        if (!$this->isServerRunning($pidfile)) {
            $io->warning('Server is not running');
            return Command::SUCCESS;
        }
        
        $pid = trim(file_get_contents($pidfile));
        
        $io->text("Stopping server (PID: {$pid})...");
        
        // Send SIGTERM to gracefully stop the server
        if (posix_kill((int) $pid, SIGTERM)) {
            // Wait for graceful shutdown
            $timeout = 10;
            while ($timeout > 0 && $this->isServerRunning($pidfile)) {
                sleep(1);
                $timeout--;
            }
            
            if ($this->isServerRunning($pidfile)) {
                // Force kill if graceful shutdown failed
                posix_kill((int) $pid, SIGKILL);
                $io->warning('Server was force killed');
            } else {
                $io->success('Server stopped gracefully');
            }
            
            // Clean up PID file
            if (file_exists($pidfile)) {
                unlink($pidfile);
            }
        } else {
            $io->error('Failed to stop server');
            return Command::FAILURE;
        }
        
        return Command::SUCCESS;
    }

    private function restartServer(InputInterface $input, SymfonyStyle $io): int
    {
        $io->text('Restarting server...');
        
        // Stop the server
        $this->stopServer($input, $io);
        
        // Wait a moment
        sleep(1);
        
        // Start the server
        return $this->startServer($input, $io);
    }

    private function serverStatus(InputInterface $input, SymfonyStyle $io): int
    {
        $pidfile = $input->getOption('pidfile');
        $host = $input->getOption('host');
        $port = $input->getOption('port');
        
        if ($this->isServerRunning($pidfile)) {
            $pid = trim(file_get_contents($pidfile));
            $io->success("Server is running (PID: {$pid})");
            $io->text("Server URL: http://{$host}:{$port}");
            
            // Try to get additional server info
            $this->displayServerInfo($io, $host, $port);
        } else {
            $io->error('Server is not running');
            return Command::FAILURE;
        }
        
        return Command::SUCCESS;
    }

    private function reloadServer(InputInterface $input, SymfonyStyle $io): int
    {
        $pidfile = $input->getOption('pidfile');
        
        if (!$this->isServerRunning($pidfile)) {
            $io->error('Server is not running');
            return Command::FAILURE;
        }
        
        $pid = trim(file_get_contents($pidfile));
        
        $io->text("Reloading server configuration (PID: {$pid})...");
        
        // Send SIGUSR1 for graceful reload
        if (posix_kill((int) $pid, SIGUSR1)) {
            $io->success('Server configuration reloaded');
        } else {
            $io->error('Failed to reload server');
            return Command::FAILURE;
        }
        
        return Command::SUCCESS;
    }

    private function isServerRunning(string $pidfile): bool
    {
        if (!file_exists($pidfile)) {
            return false;
        }
        
        $pid = trim(file_get_contents($pidfile));
        if (empty($pid)) {
            return false;
        }
        
        // Check if process is still running
        return posix_kill((int) $pid, 0);
    }

    private function createServerScript(string $host, string $port, int $workers, bool $daemon): string
    {
        $scriptPath = 'storage/server_start.php';
        
        if (!is_dir('storage')) {
            mkdir('storage', 0755, true);
        }
        
        $script = <<<PHP
<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../core/helpers.php';

use HybridPHP\Core\Application;
use HybridPHP\Core\ConfigManager;

// Load environment variables
if (file_exists(__DIR__ . '/../.env')) {
    \$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    \$dotenv->load();
}

// Initialize application
\$config = new ConfigManager();
\$config->load(__DIR__ . '/../config/main.php', 'main');

\$app = new Application(\$config);

// Configure server
\$serverConfig = [
    'host' => '{$host}',
    'port' => {$port},
    'workers' => {$workers},
    'daemon' => {$daemon}
];

// Start server
\$app->startServer(\$serverConfig);
PHP;
        
        file_put_contents($scriptPath, $script);
        
        return $scriptPath;
    }

    private function displayServerInfo(SymfonyStyle $io, string $host, string $port): void
    {
        try {
            // Try to get server health info
            $context = stream_context_create([
                'http' => [
                    'timeout' => 2,
                    'method' => 'GET'
                ]
            ]);
            
            $healthUrl = "http://{$host}:{$port}/health";
            $response = @file_get_contents($healthUrl, false, $context);
            
            if ($response !== false) {
                $healthData = json_decode($response, true);
                if ($healthData) {
                    $io->text("Health Status: " . ($healthData['status'] ?? 'unknown'));
                    if (isset($healthData['uptime'])) {
                        $io->text("Uptime: " . $healthData['uptime']);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ignore health check errors
        }
    }
}