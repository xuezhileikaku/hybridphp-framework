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
 * Task scheduling command
 */
class ScheduleCommand extends Command
{
    protected static $defaultName = 'schedule';
    protected static $defaultDescription = 'Manage scheduled tasks';

    protected function configure(): void
    {
        $this
            ->setDescription('Manage scheduled tasks (run, list, add)')
            ->setHelp('This command manages the task scheduler')
            ->addArgument(
                'action',
                InputArgument::REQUIRED,
                'Action to perform (run, list, add, remove)'
            )
            ->addArgument(
                'task',
                InputArgument::OPTIONAL,
                'Task name (for add/remove actions)'
            )
            ->addOption(
                'cron',
                'c',
                InputOption::VALUE_REQUIRED,
                'Cron expression for scheduled task'
            )
            ->addOption(
                'command',
                null,
                InputOption::VALUE_REQUIRED,
                'Command to execute'
            )
            ->addOption(
                'daemon',
                'd',
                InputOption::VALUE_NONE,
                'Run scheduler in daemon mode'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');
        
        try {
            switch ($action) {
                case 'run':
                    return $this->runScheduler($input, $io);
                case 'list':
                    return $this->listTasks($input, $io);
                case 'add':
                    return $this->addTask($input, $io);
                case 'remove':
                    return $this->removeTask($input, $io);
                default:
                    $io->error("Unknown action: {$action}");
                    $io->text("Available actions: run, list, add, remove");
                    return Command::FAILURE;
            }
        } catch (\Throwable $e) {
            $io->error('Schedule command failed: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $io->text($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    private function runScheduler(InputInterface $input, SymfonyStyle $io): int
    {
        $daemon = $input->getOption('daemon');
        
        $io->title('HybridPHP Task Scheduler');
        
        if ($daemon) {
            $io->text('Starting scheduler in daemon mode...');
            $this->runDaemon($io);
        } else {
            $io->text('Running scheduled tasks...');
            $this->runTasks($io);
        }
        
        return Command::SUCCESS;
    }

    private function listTasks(InputInterface $input, SymfonyStyle $io): int
    {
        $io->title('Scheduled Tasks');
        
        $tasks = $this->getScheduledTasks();
        
        if (empty($tasks)) {
            $io->text('No scheduled tasks found.');
            return Command::SUCCESS;
        }
        
        $tableData = [];
        foreach ($tasks as $name => $task) {
            $tableData[] = [
                $name,
                $task['cron'],
                $task['command'],
                $task['enabled'] ? 'Yes' : 'No',
                $task['last_run'] ?? 'Never'
            ];
        }
        
        $io->table(
            ['Name', 'Schedule', 'Command', 'Enabled', 'Last Run'],
            $tableData
        );
        
        return Command::SUCCESS;
    }

    private function addTask(InputInterface $input, SymfonyStyle $io): int
    {
        $taskName = $input->getArgument('task');
        $cron = $input->getOption('cron');
        $command = $input->getOption('command');
        
        if (!$taskName || !$cron || !$command) {
            $io->error('Task name, cron expression, and command are required for adding tasks.');
            $io->text('Usage: php bin/hybrid schedule add task_name --cron="0 * * * *" --command="some:command"');
            return Command::FAILURE;
        }
        
        // Validate cron expression
        if (!$this->isValidCronExpression($cron)) {
            $io->error('Invalid cron expression. Use format: "minute hour day month weekday"');
            return Command::FAILURE;
        }
        
        $tasks = $this->getScheduledTasks();
        $tasks[$taskName] = [
            'cron' => $cron,
            'command' => $command,
            'enabled' => true,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        $this->saveScheduledTasks($tasks);
        
        $io->success("Task '{$taskName}' added successfully.");
        $io->text("Schedule: {$cron}");
        $io->text("Command: {$command}");
        
        return Command::SUCCESS;
    }

    private function removeTask(InputInterface $input, SymfonyStyle $io): int
    {
        $taskName = $input->getArgument('task');
        
        if (!$taskName) {
            $io->error('Task name is required for removing tasks.');
            return Command::FAILURE;
        }
        
        $tasks = $this->getScheduledTasks();
        
        if (!isset($tasks[$taskName])) {
            $io->error("Task '{$taskName}' not found.");
            return Command::FAILURE;
        }
        
        unset($tasks[$taskName]);
        $this->saveScheduledTasks($tasks);
        
        $io->success("Task '{$taskName}' removed successfully.");
        
        return Command::SUCCESS;
    }

    private function runDaemon(SymfonyStyle $io): void
    {
        $io->text('Scheduler daemon started. Press Ctrl+C to stop.');
        
        while (true) {
            $this->runTasks($io);
            sleep(60); // Check every minute
        }
    }

    private function runTasks(SymfonyStyle $io): void
    {
        $tasks = $this->getScheduledTasks();
        $now = new \DateTime();
        
        foreach ($tasks as $name => $task) {
            if (!$task['enabled']) {
                continue;
            }
            
            if ($this->shouldRunTask($task, $now)) {
                $io->text("Running task: {$name}");
                $this->executeTask($task, $io);
                $this->updateTaskLastRun($name);
            }
        }
    }

    private function shouldRunTask(array $task, \DateTime $now): bool
    {
        // Simple cron expression parser
        $cronParts = explode(' ', $task['cron']);
        if (count($cronParts) !== 5) {
            return false;
        }
        
        [$minute, $hour, $day, $month, $weekday] = $cronParts;
        
        // Check if current time matches cron expression
        $currentMinute = (int) $now->format('i');
        $currentHour = (int) $now->format('H');
        $currentDay = (int) $now->format('d');
        $currentMonth = (int) $now->format('n');
        $currentWeekday = (int) $now->format('w');
        
        return $this->matchesCronField($minute, $currentMinute) &&
               $this->matchesCronField($hour, $currentHour) &&
               $this->matchesCronField($day, $currentDay) &&
               $this->matchesCronField($month, $currentMonth) &&
               $this->matchesCronField($weekday, $currentWeekday);
    }

    private function matchesCronField(string $cronField, int $currentValue): bool
    {
        if ($cronField === '*') {
            return true;
        }
        
        if (is_numeric($cronField)) {
            return (int) $cronField === $currentValue;
        }
        
        // Handle ranges (e.g., 1-5)
        if (strpos($cronField, '-') !== false) {
            [$start, $end] = explode('-', $cronField);
            return $currentValue >= (int) $start && $currentValue <= (int) $end;
        }
        
        // Handle lists (e.g., 1,3,5)
        if (strpos($cronField, ',') !== false) {
            $values = array_map('intval', explode(',', $cronField));
            return in_array($currentValue, $values);
        }
        
        // Handle step values (e.g., */5)
        if (strpos($cronField, '/') !== false) {
            [$range, $step] = explode('/', $cronField);
            if ($range === '*') {
                return $currentValue % (int) $step === 0;
            }
        }
        
        return false;
    }

    private function executeTask(array $task, SymfonyStyle $io): void
    {
        $command = $task['command'];
        
        // If it's a HybridPHP command, run it through the console application
        if (strpos($command, ':') !== false) {
            $command = "php bin/hybrid {$command}";
        }
        
        $output = [];
        $returnCode = 0;
        exec($command . ' 2>&1', $output, $returnCode);
        
        if ($returnCode === 0) {
            $io->text('âœ?Task completed successfully');
        } else {
            $io->error('â?Task failed with exit code: ' . $returnCode);
            if (!empty($output)) {
                $io->text('Output:');
                foreach ($output as $line) {
                    $io->text('  ' . $line);
                }
            }
        }
    }

    private function getScheduledTasks(): array
    {
        $tasksFile = $this->getTasksFilePath();
        
        if (!file_exists($tasksFile)) {
            return [];
        }
        
        $content = file_get_contents($tasksFile);
        return json_decode($content, true) ?: [];
    }

    private function saveScheduledTasks(array $tasks): void
    {
        $tasksFile = $this->getTasksFilePath();
        $tasksDir = dirname($tasksFile);
        
        if (!is_dir($tasksDir)) {
            mkdir($tasksDir, 0755, true);
        }
        
        file_put_contents($tasksFile, json_encode($tasks, JSON_PRETTY_PRINT));
    }

    private function updateTaskLastRun(string $taskName): void
    {
        $tasks = $this->getScheduledTasks();
        if (isset($tasks[$taskName])) {
            $tasks[$taskName]['last_run'] = date('Y-m-d H:i:s');
            $this->saveScheduledTasks($tasks);
        }
    }

    private function getTasksFilePath(): string
    {
        return 'storage/schedule/tasks.json';
    }

    private function isValidCronExpression(string $cron): bool
    {
        $parts = explode(' ', $cron);
        return count($parts) === 5;
    }
}