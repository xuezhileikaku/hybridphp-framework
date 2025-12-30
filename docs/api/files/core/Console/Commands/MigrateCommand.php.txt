<?php

declare(strict_types=1);

namespace HybridPHP\Core\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use HybridPHP\Core\Database\Migration\MigrationManager;
use HybridPHP\Core\Database\DatabaseManager;
use HybridPHP\Core\ConfigManager;
use function Amp\async;
use function Amp\await;

/**
 * Database migration command
 */
class MigrateCommand extends Command
{
    protected static $defaultName = 'migrate';
    protected static $defaultDescription = 'Run database migrations';

    private MigrationManager $migrationManager;

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Run database migrations')
            ->setHelp('This command allows you to run database migrations')
            ->addOption(
                'rollback',
                'r',
                InputOption::VALUE_OPTIONAL,
                'Rollback migrations (specify number of steps or "all")',
                false
            )
            ->addOption(
                'status',
                's',
                InputOption::VALUE_NONE,
                'Show migration status'
            )
            ->addOption(
                'create',
                'c',
                InputOption::VALUE_REQUIRED,
                'Create a new migration file'
            )
            ->addOption(
                'database',
                'd',
                InputOption::VALUE_REQUIRED,
                'Database connection to use',
                'mysql'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force migration in production'
            )
            ->addOption(
                'fresh',
                null,
                InputOption::VALUE_NONE,
                'Drop all tables and re-run all migrations'
            )
            ->addOption(
                'refresh',
                null,
                InputOption::VALUE_NONE,
                'Rollback all migrations and re-run them'
            )
            ->addOption(
                'reset',
                null,
                InputOption::VALUE_NONE,
                'Rollback all migrations'
            )
            ->addOption(
                'step',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of migrations to rollback',
                1
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        try {
            // Initialize migration manager
            $this->initializeMigrationManager($input->getOption('database'));
            
            if ($input->getOption('create')) {
                return $this->createMigration($io, $input->getOption('create'));
            }
            
            if ($input->getOption('status')) {
                return $this->showStatus($io);
            }
            
            if ($input->getOption('fresh')) {
                return $this->freshMigrations($io, $input->getOption('force'));
            }
            
            if ($input->getOption('refresh')) {
                return $this->refreshMigrations($io, $input->getOption('force'));
            }
            
            if ($input->getOption('reset')) {
                return $this->resetMigrations($io);
            }
            
            if ($input->getOption('rollback') !== false) {
                $steps = $input->getOption('rollback') ?: $input->getOption('step');
                return $this->rollbackMigrations($io, $steps);
            }
            
            return $this->runMigrations($io, $input->getOption('force'));
            
        } catch (\Throwable $e) {
            $io->error('Migration failed: ' . $e->getMessage());
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

    private function createMigration(SymfonyStyle $io, string $name): int
    {
        $filename = $this->migrationManager->createMigration($name);
        $io->success("Migration created: $filename");
        return Command::SUCCESS;
    }

    private function showStatus(SymfonyStyle $io): int
    {
        $status = await($this->migrationManager->getStatus());
        
        if (empty($status)) {
            $io->info('No migrations found.');
            return Command::SUCCESS;
        }
        
        $io->title('Migration Status');
        
        $rows = [];
        foreach ($status as $migration) {
            $rows[] = [
                $migration['filename'],
                $migration['batch'] ?? 'Pending',
                $migration['executed_at'] ?? 'Not executed'
            ];
        }
        
        $io->table(['Migration', 'Batch', 'Executed At'], $rows);
        
        return Command::SUCCESS;
    }

    private function rollbackMigrations(SymfonyStyle $io, ?string $steps): int
    {
        if ($steps === null) {
            $steps = '1';
        }
        
        $io->title('Rolling back migrations');
        
        if ($steps === 'all') {
            $rolledBack = await($this->migrationManager->rollbackAll());
        } else {
            $rolledBack = await($this->migrationManager->rollback((int)$steps));
        }
        
        if (empty($rolledBack)) {
            $io->info('No migrations to rollback.');
        } else {
            foreach ($rolledBack as $migration) {
                $io->writeln("Rolled back: {$migration}");
            }
            $io->success('Rollback completed successfully.');
        }
        
        return Command::SUCCESS;
    }

    private function runMigrations(SymfonyStyle $io, bool $force): int
    {
        // Check if we're in production and force is not set
        if (!$force && ($_ENV['APP_ENV'] ?? 'production') === 'production') {
            if (!$io->confirm('You are running migrations in production. Are you sure?', false)) {
                $io->info('Migration cancelled.');
                return Command::SUCCESS;
            }
        }
        
        $io->title('Running migrations');
        
        $migrations = await($this->migrationManager->migrate());
        
        if (empty($migrations)) {
            $io->info('No pending migrations.');
        } else {
            foreach ($migrations as $migration) {
                $io->writeln("Migrated: {$migration}");
            }
            $io->success('Migrations completed successfully.');
        }
        
        return Command::SUCCESS;
    }

    private function freshMigrations(SymfonyStyle $io, bool $force): int
    {
        // Check if we're in production and force is not set
        if (!$force && ($_ENV['APP_ENV'] ?? 'production') === 'production') {
            if (!$io->confirm('You are running fresh migrations in production. This will DROP ALL TABLES! Are you sure?', false)) {
                $io->info('Fresh migration cancelled.');
                return Command::SUCCESS;
            }
        }
        
        $io->title('Running fresh migrations (dropping all tables)');
        
        // Drop all tables first
        await($this->migrationManager->dropAllTables());
        $io->writeln('All tables dropped.');
        
        // Run all migrations
        $migrations = await($this->migrationManager->migrate());
        
        if (empty($migrations)) {
            $io->info('No migrations to run.');
        } else {
            foreach ($migrations as $migration) {
                $io->writeln("Migrated: {$migration}");
            }
            $io->success('Fresh migrations completed successfully.');
        }
        
        return Command::SUCCESS;
    }

    private function refreshMigrations(SymfonyStyle $io, bool $force): int
    {
        // Check if we're in production and force is not set
        if (!$force && ($_ENV['APP_ENV'] ?? 'production') === 'production') {
            if (!$io->confirm('You are refreshing migrations in production. This will rollback ALL migrations! Are you sure?', false)) {
                $io->info('Refresh migration cancelled.');
                return Command::SUCCESS;
            }
        }
        
        $io->title('Refreshing migrations (rollback all and re-run)');
        
        // Rollback all migrations
        $rolledBack = await($this->migrationManager->rollbackAll());
        
        if (!empty($rolledBack)) {
            foreach ($rolledBack as $migration) {
                $io->writeln("Rolled back: {$migration}");
            }
        }
        
        // Run all migrations again
        $migrations = await($this->migrationManager->migrate());
        
        if (!empty($migrations)) {
            foreach ($migrations as $migration) {
                $io->writeln("Migrated: {$migration}");
            }
        }
        
        $io->success('Refresh migrations completed successfully.');
        return Command::SUCCESS;
    }

    private function resetMigrations(SymfonyStyle $io): int
    {
        $io->title('Resetting migrations (rollback all)');
        
        $rolledBack = await($this->migrationManager->rollbackAll());
        
        if (empty($rolledBack)) {
            $io->info('No migrations to rollback.');
        } else {
            foreach ($rolledBack as $migration) {
                $io->writeln("Rolled back: {$migration}");
            }
            $io->success('Reset completed successfully.');
        }
        
        return Command::SUCCESS;
    }
}