<?php

declare(strict_types=1);

namespace HybridPHP\Core\Console\Commands;

use HybridPHP\Core\Health\HealthCheckManager;
use HybridPHP\Core\Health\MonitoringService;
use HybridPHP\Core\Container;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use function Amp\async;

/**
 * Health check console command
 */
class HealthCheckCommand extends Command
{
    protected static $defaultName = 'health:check';
    protected static $defaultDescription = 'Run health checks and display results';

    private Container $container;

    public function __construct(Container $container)
    {
        parent::__construct();
        $this->container = $container;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Run health checks and display results')
            ->addArgument('check', InputArgument::OPTIONAL, 'Specific health check to run')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format (json, prometheus, elk, table)', 'table')
            ->addOption('watch', 'w', InputOption::VALUE_NONE, 'Watch mode - continuously run health checks')
            ->addOption('interval', 'i', InputOption::VALUE_REQUIRED, 'Watch interval in seconds', '30')
            ->addOption('fail-on-error', null, InputOption::VALUE_NONE, 'Exit with error code if any check fails')
            ->addOption('timeout', 't', InputOption::VALUE_REQUIRED, 'Timeout for health checks in seconds', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        if (!$this->container->has(HealthCheckManager::class)) {
            $io->error('Health check system is not configured. Please check your configuration.');
            return Command::FAILURE;
        }

        $healthCheckManager = $this->container->get(HealthCheckManager::class);
        $specificCheck = $input->getArgument('check');
        $format = $input->getOption('format');
        $watch = $input->getOption('watch');
        $interval = (int) $input->getOption('interval');
        $failOnError = $input->getOption('fail-on-error');

        if ($watch) {
            return $this->runWatchMode($io, $healthCheckManager, $specificCheck, $format, $interval, $failOnError);
        }

        try {
            if ($specificCheck) {
                $result = $healthCheckManager->check($specificCheck)->await();
                $this->displaySingleResult($io, $result, $format);
                
                if ($failOnError && !$result->isHealthy()) {
                    return Command::FAILURE;
                }
            } else {
                $report = $healthCheckManager->checkAll()->await();
                $this->displayReport($io, $report, $format);
                
                if ($failOnError && !$report->isHealthy()) {
                    return Command::FAILURE;
                }
            }
            
            return Command::SUCCESS;
            
        } catch (\Throwable $e) {
            $io->error('Health check failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function runWatchMode(
        SymfonyStyle $io,
        HealthCheckManager $healthCheckManager,
        ?string $specificCheck,
        string $format,
        int $interval,
        bool $failOnError
    ): int {
        $io->title('Health Check Watch Mode');
        $io->note("Running health checks every {$interval} seconds. Press Ctrl+C to stop.");

        while (true) {
            try {
                $io->section('Health Check - ' . date('Y-m-d H:i:s'));
                
                if ($specificCheck) {
                    $result = $healthCheckManager->check($specificCheck)->await();
                    $this->displaySingleResult($io, $result, $format);
                    
                    if ($failOnError && !$result->isHealthy()) {
                        return Command::FAILURE;
                    }
                } else {
                    $report = $healthCheckManager->checkAll()->await();
                    $this->displayReport($io, $report, $format);
                    
                    if ($failOnError && !$report->isHealthy()) {
                        return Command::FAILURE;
                    }
                }
                
                // Wait for next check
                sleep($interval);
                
            } catch (\Throwable $e) {
                $io->error('Health check failed: ' . $e->getMessage());
                
                if ($failOnError) {
                    return Command::FAILURE;
                }
                
                sleep($interval);
            }
        }
    }

    private function displaySingleResult(SymfonyStyle $io, $result, string $format): void
    {
        switch ($format) {
            case 'json':
                $io->writeln(json_encode($result->toArray(), JSON_PRETTY_PRINT));
                break;
                
            case 'prometheus':
                $io->writeln("# Health check result for {$result->getName()}");
                $status = $result->isHealthy() ? 1 : 0;
                $io->writeln("hybridphp_health_check_status{check=\"{$result->getName()}\"} {$status}");
                $io->writeln("hybridphp_health_check_response_time{check=\"{$result->getName()}\"} {$result->getResponseTime()}");
                break;
                
            case 'elk':
                $elkData = [
                    '@timestamp' => date('c', $result->getTimestamp()),
                    'service' => 'hybridphp',
                    'type' => 'health_check',
                    'check' => $result->toArray()
                ];
                $io->writeln(json_encode($elkData, JSON_PRETTY_PRINT));
                break;
                
            default: // table
                $this->displayTableResult($io, $result);
                break;
        }
    }

    private function displayReport(SymfonyStyle $io, $report, string $format): void
    {
        switch ($format) {
            case 'json':
                $io->writeln(json_encode($report->toArray(), JSON_PRETTY_PRINT));
                break;
                
            case 'prometheus':
                $io->writeln($report->toPrometheusFormat());
                break;
                
            case 'elk':
                $io->writeln(json_encode($report->toElkFormat(), JSON_PRETTY_PRINT));
                break;
                
            default: // table
                $this->displayTableReport($io, $report);
                break;
        }
    }

    private function displayTableResult(SymfonyStyle $io, $result): void
    {
        $status = $result->getStatus();
        $statusColor = $this->getStatusColor($status);
        
        $io->section("Health Check: {$result->getName()}");
        
        $table = [
            ['Status', "<{$statusColor}>{$status}</{$statusColor}>"],
            ['Response Time', number_format($result->getResponseTime(), 3) . 's'],
            ['Timestamp', date('Y-m-d H:i:s', $result->getTimestamp())],
        ];
        
        if ($result->getMessage()) {
            $table[] = ['Message', $result->getMessage()];
        }
        
        if ($result->getException()) {
            $table[] = ['Error', $result->getException()->getMessage()];
        }
        
        $io->definitionList(...$table);
        
        if (!empty($result->getData())) {
            $io->section('Additional Data');
            $io->writeln(json_encode($result->getData(), JSON_PRETTY_PRINT));
        }
    }

    private function displayTableReport(SymfonyStyle $io, $report): void
    {
        $summary = $report->getSummary();
        $overallStatus = $summary['overall_status'];
        $statusColor = $this->getStatusColor($overallStatus);
        
        $io->title('Health Check Report');
        
        // Summary
        $io->section('Summary');
        $summaryTable = [
            ['Overall Status', "<{$statusColor}>{$overallStatus}</{$statusColor}>"],
            ['Total Checks', $summary['total']],
            ['Healthy', "<fg=green>{$summary['healthy']}</>"],
            ['Unhealthy', "<fg=red>{$summary['unhealthy']}</>"],
            ['Warning', "<fg=yellow>{$summary['warning']}</>"],
            ['Total Time', number_format($summary['total_time'], 3) . 's'],
            ['Timestamp', date('Y-m-d H:i:s', $summary['timestamp'])],
        ];
        
        $io->definitionList(...$summaryTable);
        
        // Individual checks
        $io->section('Individual Checks');
        $rows = [];
        
        foreach ($report->getResults() as $name => $result) {
            $status = $result->getStatus();
            $statusColor = $this->getStatusColor($status);
            
            $rows[] = [
                $name,
                "<{$statusColor}>{$status}</{$statusColor}>",
                number_format($result->getResponseTime(), 3) . 's',
                $result->getMessage() ?: '-'
            ];
        }
        
        $io->table(['Check', 'Status', 'Response Time', 'Message'], $rows);
        
        // Show unhealthy checks details
        $unhealthyResults = $report->getUnhealthyResults();
        if (!empty($unhealthyResults)) {
            $io->section('Failed Checks Details');
            
            foreach ($unhealthyResults as $name => $result) {
                $io->error("�?{$name}: " . ($result->getMessage() ?: 'Health check failed'));
                
                if ($result->getException()) {
                    $io->writeln("   Error: " . $result->getException()->getMessage());
                }
                
                if (!empty($result->getData())) {
                    $io->writeln("   Data: " . json_encode($result->getData()));
                }
            }
        }
        
        // Show warning checks
        $warningResults = $report->getWarningResults();
        if (!empty($warningResults)) {
            $io->section('Warning Checks');
            
            foreach ($warningResults as $name => $result) {
                $io->warning("⚠︝  {$name}: " . ($result->getMessage() ?: 'Health check warning'));
                
                if (!empty($result->getData())) {
                    $io->writeln("   Data: " . json_encode($result->getData()));
                }
            }
        }
    }

    private function getStatusColor(string $status): string
    {
        switch ($status) {
            case 'healthy':
                return 'fg=green';
            case 'unhealthy':
                return 'fg=red';
            case 'warning':
                return 'fg=yellow';
            default:
                return 'fg=gray';
        }
    }
}