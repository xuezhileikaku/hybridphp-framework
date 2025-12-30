<?php

declare(strict_types=1);

namespace HybridPHP\Core\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use HybridPHP\Core\Database\Seeder\SeederManager;
use HybridPHP\Core\Database\DatabaseManager;
use HybridPHP\Core\ConfigManager;

/**
 * Make seeder command
 */
class MakeSeederCommand extends Command
{
    protected static $defaultName = 'make:seeder';
    protected static $defaultDescription = 'Create a new seeder file';

    private SeederManager $seederManager;

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Create a new seeder file')
            ->setHelp('This command allows you to create a new seeder file')
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'The name of the seeder'
            )
            ->addOption(
                'database',
                'd',
                InputOption::VALUE_REQUIRED,
                'Database connection to use',
                'mysql'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        try {
            // Initialize seeder manager
            $this->initializeSeederManager($input->getOption('database'));
            
            $name = $input->getArgument('name');
            $filename = $this->seederManager->createSeeder($name);
            
            $io->success("Seeder created: {$filename}");
            $io->text("Edit the seeder file at: database/seeds/{$filename}");
            
            return Command::SUCCESS;
            
        } catch (\Throwable $e) {
            $io->error('Failed to create seeder: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    private function initializeSeederManager(string $database): void
    {
        // Load configuration
        $config = new ConfigManager();
        $config->load($this->getConfigPath() . '/database.php', 'database');
        $dbConfig = $config->get('database');
        
        // Initialize database manager
        $databaseManager = new DatabaseManager($dbConfig, new \Psr\Log\NullLogger());
        
        // Initialize seeder manager
        $this->seederManager = new SeederManager(
            $databaseManager->connection($database),
            $dbConfig['seeds'] ?? []
        );
    }

    private function getConfigPath(): string
    {
        return dirname(__DIR__, 3) . '/config';
    }
}