<?php

declare(strict_types=1);

namespace HybridPHP\Core\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Enhanced help command with Yii2-style experience
 */
class HelpCommand extends Command
{
    protected static $defaultName = 'hybrid:help';
    protected static $defaultDescription = 'Display help information about HybridPHP commands';

    protected function configure(): void
    {
        $this
            ->setDescription('Display help information about HybridPHP commands')
            ->setHelp('This command provides detailed help about HybridPHP Framework commands')
            ->addArgument(
                'command_name',
                InputArgument::OPTIONAL,
                'Command name to get help for'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $commandName = $input->getArgument('command_name');
        
        if ($commandName) {
            return $this->showCommandHelp($commandName, $io);
        }
        
        return $this->showGeneralHelp($io);
    }

    private function showGeneralHelp(SymfonyStyle $io): int
    {
        $io->title('HybridPHP Framework CLI Tool');
        $io->text('A powerful command-line interface for HybridPHP Framework development.');
        
        $io->section('Available Commands');
        
        $commands = [
            'Project Scaffolding' => [
                'make:project' => 'Create a new HybridPHP project with complete scaffolding',
            ],
            
            'Code Generation' => [
                'make:controller' => 'Create a new controller class',
                'make:model' => 'Create a new model class',
                'make:middleware' => 'Create a new middleware class',
                'make:migration' => 'Create a new database migration',
                'make:seeder' => 'Create a new database seeder',
            ],
            
            'Database Management' => [
                'migrate' => 'Run database migrations',
                'migrate:status' => 'Show migration status',
                'seed' => 'Run database seeders',
            ],
            
            'Server Management' => [
                'server start' => 'Start the HybridPHP server',
                'server stop' => 'Stop the HybridPHP server',
                'server restart' => 'Restart the HybridPHP server',
                'server status' => 'Show server status',
                'server reload' => 'Reload server configuration',
            ],
            
            'Development Tools' => [
                'code:check' => 'Run code quality checks and analysis',
                'schedule' => 'Manage scheduled tasks',
            ],
            
            'System' => [
                'help' => 'Display this help message',
                'list' => 'List all available commands',
            ],
        ];
        
        foreach ($commands as $category => $categoryCommands) {
            $io->text("<fg=yellow>{$category}:</>");
            foreach ($categoryCommands as $command => $description) {
                $io->text("  <fg=green>{$command}</> - {$description}");
            }
            $io->newLine();
        }
        
        $io->section('Quick Start');
        $io->listing([
            'Create a new project: <fg=cyan>php bin/hybrid make:project MyApp</fg=cyan>',
            'Generate a controller: <fg=cyan>php bin/hybrid make:controller UserController --resource</fg=cyan>',
            'Create a model with migration: <fg=cyan>php bin/hybrid make:model User --migration</fg=cyan>',
            'Run migrations: <fg=cyan>php bin/hybrid migrate</fg=cyan>',
            'Start the server: <fg=cyan>php bin/hybrid server start</fg=cyan>',
            'Check code quality: <fg=cyan>php bin/hybrid code:check</fg=cyan>',
        ]);
        
        $io->section('Examples');
        
        $examples = [
            'Create API project with authentication' => 'php bin/hybrid make:project MyAPI --type=api --auth',
            'Generate resource controller' => 'php bin/hybrid make:controller PostController --resource',
            'Create model with migration and controller' => 'php bin/hybrid make:model Post --migration --controller --resource',
            'Start server on custom port' => 'php bin/hybrid server start --port=9000 --workers=8',
            'Run specific code check' => 'php bin/hybrid code:check phpstan --level=8',
            'Add scheduled task' => 'php bin/hybrid schedule add backup --cron="0 2 * * *" --command="backup:run"',
        ];
        
        foreach ($examples as $description => $command) {
            $io->text("<fg=yellow>{$description}:</>");
            $io->text("  <fg=cyan>{$command}</>");
            $io->newLine();
        }
        
        $io->note('For detailed help on a specific command, use: php bin/hybrid help <command>');
        $io->text('Documentation: https://hybridphp.dev/docs');
        
        return Command::SUCCESS;
    }

    private function showCommandHelp(string $commandName, SymfonyStyle $io): int
    {
        $commandHelp = $this->getCommandHelp($commandName);
        
        if (!$commandHelp) {
            $io->error("Command '{$commandName}' not found.");
            $io->text('Use "php bin/hybrid help" to see all available commands.');
            return Command::FAILURE;
        }
        
        $io->title("Help for '{$commandName}' command");
        
        $io->section('Description');
        $io->text($commandHelp['description']);
        
        if (!empty($commandHelp['usage'])) {
            $io->section('Usage');
            foreach ($commandHelp['usage'] as $usage) {
                $io->text("  <fg=cyan>{$usage}</>");
            }
        }
        
        if (!empty($commandHelp['arguments'])) {
            $io->section('Arguments');
            foreach ($commandHelp['arguments'] as $arg => $desc) {
                $io->text("  <fg=green>{$arg}</> - {$desc}");
            }
        }
        
        if (!empty($commandHelp['options'])) {
            $io->section('Options');
            foreach ($commandHelp['options'] as $option => $desc) {
                $io->text("  <fg=green>{$option}</> - {$desc}");
            }
        }
        
        if (!empty($commandHelp['examples'])) {
            $io->section('Examples');
            foreach ($commandHelp['examples'] as $example) {
                $io->text("  <fg=cyan>{$example}</>");
            }
        }
        
        return Command::SUCCESS;
    }

    private function getCommandHelp(string $commandName): ?array
    {
        $helpData = [
            'make:project' => [
                'description' => 'Create a new HybridPHP project with complete scaffolding including controllers, models, configuration, and optional authentication.',
                'usage' => [
                    'php bin/hybrid make:project <name>',
                    'php bin/hybrid make:project <name> --type=api --auth --database=mysql'
                ],
                'arguments' => [
                    'name' => 'The name of the project'
                ],
                'options' => [
                    '--type=TYPE' => 'Project type: api, web, or full (default: full)',
                    '--auth' => 'Include authentication scaffolding',
                    '--database=DB' => 'Database type: mysql or postgresql (default: mysql)'
                ],
                'examples' => [
                    'php bin/hybrid make:project BlogApp',
                    'php bin/hybrid make:project MyAPI --type=api --auth',
                    'php bin/hybrid make:project WebApp --type=web --database=postgresql'
                ]
            ],
            
            'make:controller' => [
                'description' => 'Create a new controller class with optional resource methods and API support.',
                'usage' => [
                    'php bin/hybrid make:controller <name>',
                    'php bin/hybrid make:controller <name> --resource --api'
                ],
                'arguments' => [
                    'name' => 'The name of the controller'
                ],
                'options' => [
                    '--resource' => 'Generate a resource controller with CRUD methods',
                    '--api' => 'Generate an API controller'
                ],
                'examples' => [
                    'php bin/hybrid make:controller UserController',
                    'php bin/hybrid make:controller PostController --resource',
                    'php bin/hybrid make:controller ApiController --api'
                ]
            ],
            
            'make:model' => [
                'description' => 'Create a new model class with optional migration and controller generation.',
                'usage' => [
                    'php bin/hybrid make:model <name>',
                    'php bin/hybrid make:model <name> --migration --controller --resource'
                ],
                'arguments' => [
                    'name' => 'The name of the model'
                ],
                'options' => [
                    '--migration' => 'Also create a migration file',
                    '--controller' => 'Also create a controller',
                    '--resource' => 'Create a resource controller (used with --controller)'
                ],
                'examples' => [
                    'php bin/hybrid make:model User',
                    'php bin/hybrid make:model Post --migration',
                    'php bin/hybrid make:model Product --migration --controller --resource'
                ]
            ],
            
            'server' => [
                'description' => 'Manage the HybridPHP server with start, stop, restart, status, and reload actions.',
                'usage' => [
                    'php bin/hybrid server <action>',
                    'php bin/hybrid server start --host=127.0.0.1 --port=8080 --workers=4'
                ],
                'arguments' => [
                    'action' => 'Action to perform: start, stop, restart, status, reload'
                ],
                'options' => [
                    '--host=HOST' => 'Server host (default: 127.0.0.1)',
                    '--port=PORT' => 'Server port (default: 8080)',
                    '--workers=NUM' => 'Number of worker processes (default: 4)',
                    '--daemon' => 'Run server in daemon mode',
                    '--pidfile=FILE' => 'PID file location (default: storage/server.pid)'
                ],
                'examples' => [
                    'php bin/hybrid server start',
                    'php bin/hybrid server start --port=9000 --daemon',
                    'php bin/hybrid server stop',
                    'php bin/hybrid server status'
                ]
            ],
            
            'code:check' => [
                'description' => 'Run code quality checks including PHPStan, PHP_CodeSniffer, and custom framework-specific checks.',
                'usage' => [
                    'php bin/hybrid code:check [tool]',
                    'php bin/hybrid code:check phpstan --level=8 --path=app'
                ],
                'arguments' => [
                    'tool' => 'Specific tool to run: phpstan, phpcs, phpcbf, or all (default: all)'
                ],
                'options' => [
                    '--path=PATH' => 'Path to analyze (default: app)',
                    '--fix' => 'Automatically fix code style issues',
                    '--level=LEVEL' => 'PHPStan analysis level 0-9 (default: 5)',
                    '--standard=STD' => 'Coding standard for PHPCS (default: PSR12)'
                ],
                'examples' => [
                    'php bin/hybrid code:check',
                    'php bin/hybrid code:check phpstan --level=8',
                    'php bin/hybrid code:check phpcs --fix',
                    'php bin/hybrid code:check all --path=core'
                ]
            ],
            
            'schedule' => [
                'description' => 'Manage scheduled tasks with cron-like functionality for background job processing.',
                'usage' => [
                    'php bin/hybrid schedule <action>',
                    'php bin/hybrid schedule add <task> --cron="0 * * * *" --command="some:command"'
                ],
                'arguments' => [
                    'action' => 'Action to perform: run, list, add, remove',
                    'task' => 'Task name (for add/remove actions)'
                ],
                'options' => [
                    '--cron=EXPR' => 'Cron expression for scheduled task',
                    '--command=CMD' => 'Command to execute',
                    '--daemon' => 'Run scheduler in daemon mode'
                ],
                'examples' => [
                    'php bin/hybrid schedule list',
                    'php bin/hybrid schedule add backup --cron="0 2 * * *" --command="backup:run"',
                    'php bin/hybrid schedule run --daemon',
                    'php bin/hybrid schedule remove backup'
                ]
            ]
        ];
        
        return $helpData[$commandName] ?? null;
    }
}