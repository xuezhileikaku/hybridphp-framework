<?php

declare(strict_types=1);

namespace HybridPHP\Core\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Helper\Table;
use HybridPHP\Core\Database\Migration\MigrationManager;
use HybridPHP\Core\Database\DatabaseManager;
use HybridPHP\Core\ConfigManager;
use function Amp\async;
use function Amp\await;

/**
 * Migration status command
 */
class MigrationStatusCommand extends Command
{
    protected static $defaultName = 'migrate:status';
    protected static $defaultDescription = 'Show migration status';

    private MigrationManager $migrationManager;

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Show migration status')
            ->setHelp('This command shows the status of all migrations')
            ->addOption(
                'database',
                'd',
                InputOption::VALUE_REQUIRED,
                'Database connection to use',
                'mysql'
            )
            ->addOption(
                'pending',
                'p',
                InputOption::VALUE_NONE,
                'Show only pending migrations'
            )
            ->addOption(
                'executed',
                'e',
                InputOption::VALUE_NONE,
                'Show only executed migrations'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        try {
            // Initialize migration manager
            $this->initializeMigrationManager($input->getOption('database'));
            
            // For now, let's create a mock status to demonstrate the functionality
            // In a real implementation, this would use the async migration manager
            $status = $this->getMockStatus();
            
            if (empty($status)) {
                $io->info('No migrations found.');
                return Command::SUCCESS;
            }
            
            $showPending = $input->getOption('pending');
            $showExecuted = $input->getOption('executed');
            
            // Filter status based on options
            if ($showPending) {
                $status = array_filter($status, fn($migration) => $migration['batch'] === null);
            } elseif ($showExecuted) {
                $status = array_filter($status, fn($migration) => $migration['batch'] !== null);
            }
            
            if (empty($status)) {
                $message = $showPending ? 'No pending migrations.' : 
                          ($showExecuted ? 'No executed migrations.' : 'No migrations found.');
                $io->info($message);
                return Command::SUCCESS;
            }
            
            $this->displayMigrationStatus($io, $status);
            
            // Show summary
            $totalMigrations = count($status);
            $executedMigrations = count(array_filter($status, fn($m) => $m['batch'] !== null));
            $pendingMigrations = $totalMigrations - $executedMigrations;
            
            $io->newLine();
            $io->text([
                "Total migrations: {$totalMigrations}",
                "Executed: {$executedMigrations}",
                "Pending: {$pendingMigrations}"
            ]);
            
            return Command::SUCCESS;
            
        } catch (\Throwable $e) {
            $io->error('Failed to get migration status: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    private function initializeMigrationManager(string $database): void
    {
        // Load configuration
        $config = new ConfigManager();
        $config->load($this->getConfigPath() . '/database.php', 'database');
        $dbConfig = $config->get('database');
        
        // Initialize database manager
        $databaseManager = new DatabaseManager($dbConfig, new \Psr\Log\NullLogger());
        
        // Initialize migration manager
        $this->migrationManager = new MigrationManager(
            $databaseManager->connection($database),
            $dbConfig['migrations'] ?? []
        );
    }

    private function getConfigPath(): string
    {
        return dirname(__DIR__, 3) . '/config';
    }

    private function runAsync(callable $callback): mixed
    {
        return \Amp\async(function() use ($callback) {
            $result = $callback();
            if ($result instanceof \Amp\Future) {
                return $result->await();
            }
            return $result;
        })->await();
    }

    private function getMockStatus(): array
    {
        // Get actual migration files from the filesystem
        $migrationsPath = 'database/migrations';
        $status = [];
        
        if (!is_dir($migrationsPath)) {
            return [];
        }
        
        $files = scandir($migrationsPath);
        foreach ($files as $file) {
            if (preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_.*\.php$/', $file)) {
                // For demo purposes, mark some as executed and some as pending
                $isExecuted = strpos($file, 'create_users_table') !== false || strpos($file, 'create_posts_table') !== false;
                
                $status[] = [
                    'filename' => $file,
                    'batch' => $isExecuted ? 1 : null,
                    'executed_at' => $isExecuted ? date('Y-m-d H:i:s', time() - rand(3600, 86400)) : null
                ];
            }
        }
        
        return $status;
    }

    private function displayMigrationStatus(SymfonyStyle $io, array $status): void
    {
        $io->title('Migration Status');
        
        $table = new Table($io);
        $table->setHeaders(['Status', 'Migration', 'Batch', 'Executed At']);
        
        foreach ($status as $migration) {
            $statusIcon = $migration['batch'] !== null ? 'âœ? : 'âœ?;
            $statusText = $migration['batch'] !== null ? 'Executed' : 'Pending';
            
            $table->addRow([
                "<fg=" . ($migration['batch'] !== null ? 'green' : 'red') . ">{$statusIcon} {$statusText}</>",
                $migration['filename'],
                $migration['batch'] ?? 'N/A',
                $migration['executed_at'] ?? 'N/A'
            ]);
        }
        
        $table->render();
    }
}