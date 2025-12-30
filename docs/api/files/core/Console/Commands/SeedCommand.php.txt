<?php

declare(strict_types=1);

namespace HybridPHP\Core\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use HybridPHP\Core\Database\Seeder\SeederManager;
use HybridPHP\Core\Database\DatabaseManager;
use HybridPHP\Core\ConfigManager;
use function Amp\async;
use function Amp\await;

/**
 * Database seeder command
 */
class SeedCommand extends Command
{
    protected static $defaultName = 'seed';
    protected static $defaultDescription = 'Run database seeders';

    private SeederManager $seederManager;

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Run database seeders')
            ->setHelp('This command allows you to run database seeders')
            ->addOption(
                'class',
                'c',
                InputOption::VALUE_REQUIRED,
                'Run specific seeder class'
            )
            ->addOption(
                'database',
                'd',
                InputOption::VALUE_REQUIRED,
                'Database connection to use',
                'mysql'
            )
            ->addOption(
                'create',
                null,
                InputOption::VALUE_REQUIRED,
                'Create a new seeder file'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force seeding in production'
            )
            ->addOption(
                'verbose',
                'v',
                InputOption::VALUE_NONE,
                'Show detailed output'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        try {
            // Initialize seeder manager
            $this->initializeSeederManager($input->getOption('database'));
            
            if ($input->getOption('create')) {
                return $this->createSeeder($io, $input->getOption('create'));
            }
            
            return $this->runSeeders($io, $input->getOption('class'), $input->getOption('force'));
            
        } catch (\Throwable $e) {
            $io->error('Seeding failed: ' . $e->getMessage());
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

    private function createSeeder(SymfonyStyle $io, string $name): int
    {
        $filename = $this->seederManager->createSeeder($name);
        $io->success("Seeder created: $filename");
        return Command::SUCCESS;
    }

    private function runSeeders(SymfonyStyle $io, ?string $class, bool $force): int
    {
        // Check if we're in production and force is not set
        if (!$force && ($_ENV['APP_ENV'] ?? 'production') === 'production') {
            if (!$io->confirm('You are running seeders in production. Are you sure?', false)) {
                $io->info('Seeding cancelled.');
                return Command::SUCCESS;
            }
        }
        
        $io->title('Running seeders');
        
        if ($class) {
            $result = await($this->seederManager->runSeeder($class));
            $io->writeln("Seeded: {$class}");
        } else {
            $seeders = await($this->seederManager->runAll());
            
            if (empty($seeders)) {
                $io->info('No seeders found.');
            } else {
                foreach ($seeders as $seeder) {
                    $io->writeln("Seeded: {$seeder}");
                }
            }
        }
        
        $io->success('Seeding completed successfully.');
        return Command::SUCCESS;
    }
}