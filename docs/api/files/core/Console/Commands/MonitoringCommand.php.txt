<?php

declare(strict_types=1);

namespace HybridPHP\Core\Console\Commands;

use HybridPHP\Core\Monitoring\PerformanceMonitor;
use HybridPHP\Core\Monitoring\AlertManager;
use HybridPHP\Core\Monitoring\MetricsCollector;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;

/**
 * Monitoring management command
 */
class MonitoringCommand extends Command
{
    private PerformanceMonitor $performanceMonitor;
    private AlertManager $alertManager;
    private MetricsCollector $metricsCollector;

    public function __construct(
        PerformanceMonitor $performanceMonitor,
        AlertManager $alertManager,
        MetricsCollector $metricsCollector
    ) {
        parent::__construct();
        $this->performanceMonitor = $performanceMonitor;
        $this->alertManager = $alertManager;
        $this->metricsCollector = $metricsCollector;
    }

    protected function configure(): void
    {
        $this
            ->setName('monitoring')
            ->setDescription('Manage performance monitoring and alerts')
            ->addArgument('action', InputArgument::REQUIRED, 'Action to perform (status, metrics, alerts, clear)')
            ->addOption('format', 'f', InputOption::VALUE_OPTIONAL, 'Output format (table, json, prometheus)', 'table')
            ->addOption('filter', null, InputOption::VALUE_OPTIONAL, 'Filter results')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Limit number of results', 50)
            ->setHelp('
Available actions:
  status    - Show monitoring system status
  metrics   - Display current metrics
  alerts    - Show active alerts
  clear     - Clear metrics and alerts
  report    - Generate performance report
  export    - Export metrics in various formats

Examples:
  php bin/console monitoring status
  php bin/console monitoring metrics --format=json
  php bin/console monitoring alerts --filter=critical
  php bin/console monitoring export --format=prometheus
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getArgument('action');
        $format = $input->getOption('format');

        try {
            switch ($action) {
                case 'status':
                    return $this->showStatus($input, $output);
                
                case 'metrics':
                    return $this->showMetrics($input, $output);
                
                case 'alerts':
                    return $this->showAlerts($input, $output);
                
                case 'clear':
                    return $this->clearData($input, $output);
                
                case 'report':
                    return $this->generateReport($input, $output);
                
                case 'export':
                    return $this->exportMetrics($input, $output);
                
                default:
                    $output->writeln("<error>Unknown action: {$action}</error>");
                    return Command::FAILURE;
            }
        } catch (\Throwable $e) {
            $output->writeln("<error>Command failed: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }
    }

    private function showStatus(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        
        $metrics = $this->metricsCollector->getMetrics();
        $alerts = $this->alertManager->getStatistics();
        
        $status = [
            'timestamp' => date('c'),
            'metrics' => [
                'counters' => count($metrics['counters']),
                'gauges' => count($metrics['gauges']),
                'histograms' => count($metrics['histograms']),
            ],
            'alerts' => $alerts,
        ];

        if ($format === 'json') {
            $output->writeln(json_encode($status, JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        $output->writeln('<info>Monitoring System Status</info>');
        $output->writeln('========================');
        $output->writeln("Timestamp: {$status['timestamp']}");
        $output->writeln('');

        $output->writeln('<comment>Metrics:</comment>');
        $output->writeln("  Counters: {$status['metrics']['counters']}");
        $output->writeln("  Gauges: {$status['metrics']['gauges']}");
        $output->writeln("  Histograms: {$status['metrics']['histograms']}");
        $output->writeln('');

        $output->writeln('<comment>Alerts:</comment>');
        $output->writeln("  Total: {$alerts['total']}");
        $output->writeln("  Active: {$alerts['active']}");
        $output->writeln("  Resolved: {$alerts['resolved']}");

        if (!empty($alerts['by_severity'])) {
            $output->writeln('  By Severity:');
            foreach ($alerts['by_severity'] as $severity => $count) {
                $output->writeln("    {$severity}: {$count}");
            }
        }

        return Command::SUCCESS;
    }

    private function showMetrics(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        $filter = $input->getOption('filter');
        $limit = (int) $input->getOption('limit');
        
        $metrics = $this->metricsCollector->getMetrics();

        if ($format === 'json') {
            $output->writeln(json_encode($metrics, JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        if ($format === 'prometheus') {
            $output->writeln($this->metricsCollector->getPrometheusMetrics());
            return Command::SUCCESS;
        }

        $output->writeln('<info>Current Metrics</info>');
        $output->writeln('===============');

        // Show counters
        if (!empty($metrics['counters'])) {
            $output->writeln('<comment>Counters:</comment>');
            $table = new Table($output);
            $table->setHeaders(['Name', 'Value', 'Labels']);
            
            $count = 0;
            foreach ($metrics['counters'] as $counter) {
                if ($filter && strpos($counter['name'], $filter) === false) {
                    continue;
                }
                
                $labels = empty($counter['labels']) ? '-' : json_encode($counter['labels']);
                $table->addRow([$counter['name'], $counter['value'], $labels]);
                
                if (++$count >= $limit) break;
            }
            
            $table->render();
            $output->writeln('');
        }

        // Show gauges
        if (!empty($metrics['gauges'])) {
            $output->writeln('<comment>Gauges:</comment>');
            $table = new Table($output);
            $table->setHeaders(['Name', 'Value', 'Labels', 'Timestamp']);
            
            $count = 0;
            foreach ($metrics['gauges'] as $gauge) {
                if ($filter && strpos($gauge['name'], $filter) === false) {
                    continue;
                }
                
                $labels = empty($gauge['labels']) ? '-' : json_encode($gauge['labels']);
                $timestamp = date('H:i:s', (int) $gauge['timestamp']);
                $table->addRow([$gauge['name'], $gauge['value'], $labels, $timestamp]);
                
                if (++$count >= $limit) break;
            }
            
            $table->render();
            $output->writeln('');
        }

        // Show histograms
        if (!empty($metrics['histograms'])) {
            $output->writeln('<comment>Histograms:</comment>');
            $table = new Table($output);
            $table->setHeaders(['Name', 'Count', 'Sum', 'Avg', 'Labels']);
            
            $count = 0;
            foreach ($metrics['histograms'] as $histogram) {
                if ($filter && strpos($histogram['name'], $filter) === false) {
                    continue;
                }
                
                $labels = empty($histogram['labels']) ? '-' : json_encode($histogram['labels']);
                $avg = $histogram['count'] > 0 ? $histogram['sum'] / $histogram['count'] : 0;
                $table->addRow([
                    $histogram['name'],
                    $histogram['count'],
                    number_format($histogram['sum'], 4),
                    number_format($avg, 4),
                    $labels
                ]);
                
                if (++$count >= $limit) break;
            }
            
            $table->render();
        }

        return Command::SUCCESS;
    }

    private function showAlerts(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        $filter = $input->getOption('filter');
        $limit = (int) $input->getOption('limit');
        
        $alerts = $this->alertManager->getAllAlerts();

        if ($format === 'json') {
            $output->writeln(json_encode($alerts, JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        $output->writeln('<info>Alerts</info>');
        $output->writeln('======');

        if (empty($alerts)) {
            $output->writeln('<comment>No alerts found</comment>');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Name', 'Severity', 'Status', 'Count', 'First Triggered', 'Last Triggered']);

        $count = 0;
        foreach ($alerts as $alert) {
            if ($filter && $alert['severity'] !== $filter && strpos($alert['name'], $filter) === false) {
                continue;
            }

            $firstTriggered = date('Y-m-d H:i:s', $alert['first_triggered']);
            $lastTriggered = date('Y-m-d H:i:s', $alert['last_triggered']);
            
            $severityColor = $this->getSeverityColor($alert['severity']);
            $statusColor = $alert['status'] === 'active' ? 'error' : 'info';
            
            $table->addRow([
                $alert['name'],
                "<{$severityColor}>{$alert['severity']}</{$severityColor}>",
                "<{$statusColor}>{$alert['status']}</{$statusColor}>",
                $alert['count'],
                $firstTriggered,
                $lastTriggered,
            ]);

            if (++$count >= $limit) break;
        }

        $table->render();

        return Command::SUCCESS;
    }

    private function clearData(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<comment>Clearing monitoring data...</comment>');
        
        $this->metricsCollector->clear();
        $this->alertManager->clear();
        
        $output->writeln('<info>Monitoring data cleared successfully</info>');
        
        return Command::SUCCESS;
    }

    private function generateReport(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        
        $report = $this->performanceMonitor->getPerformanceReport();

        if ($format === 'json') {
            $output->writeln(json_encode($report, JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        $output->writeln('<info>Performance Report</info>');
        $output->writeln('==================');
        $output->writeln("Generated: {$report['timestamp']}");
        $output->writeln('');

        // System metrics
        $output->writeln('<comment>System Performance:</comment>');
        foreach ($report['system'] as $metric => $value) {
            $output->writeln("  {$metric}: {$value}");
        }
        $output->writeln('');

        // Application metrics
        $output->writeln('<comment>Application Performance:</comment>');
        foreach ($report['application'] as $metric => $value) {
            $output->writeln("  {$metric}: {$value}");
        }
        $output->writeln('');

        // Request statistics
        $output->writeln('<comment>Request Statistics:</comment>');
        $output->writeln("  Total Requests: {$report['requests']['total']}");
        
        if (!empty($report['requests']['by_method'])) {
            $output->writeln('  By Method:');
            foreach ($report['requests']['by_method'] as $method => $count) {
                $output->writeln("    {$method}: {$count}");
            }
        }

        if (!empty($report['requests']['by_status'])) {
            $output->writeln('  By Status:');
            foreach ($report['requests']['by_status'] as $status => $count) {
                $output->writeln("    {$status}: {$count}");
            }
        }
        $output->writeln('');

        // Coroutine statistics
        $output->writeln('<comment>Coroutine Statistics:</comment>');
        $output->writeln("  Started: {$report['coroutines']['started']}");
        $output->writeln("  Finished: {$report['coroutines']['finished']}");
        $output->writeln("  Active: {$report['coroutines']['active']}");
        $output->writeln('');

        // Active alerts
        if (!empty($report['alerts'])) {
            $output->writeln('<comment>Active Alerts:</comment>');
            foreach ($report['alerts'] as $alert) {
                $severityColor = $this->getSeverityColor($alert['severity']);
                $output->writeln("  <{$severityColor}>{$alert['name']} ({$alert['severity']})</{$severityColor}>");
            }
        } else {
            $output->writeln('<info>No active alerts</info>');
        }

        return Command::SUCCESS;
    }

    private function exportMetrics(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');

        switch ($format) {
            case 'prometheus':
                $output->writeln($this->metricsCollector->getPrometheusMetrics());
                break;
            
            case 'json':
                $output->writeln(json_encode($this->metricsCollector->getJsonMetrics(), JSON_PRETTY_PRINT));
                break;
            
            default:
                $output->writeln("<error>Unsupported export format: {$format}</error>");
                return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function getSeverityColor(string $severity): string
    {
        switch ($severity) {
            case 'critical':
                return 'error';
            case 'warning':
                return 'comment';
            case 'info':
                return 'info';
            default:
                return 'comment';
        }
    }
}