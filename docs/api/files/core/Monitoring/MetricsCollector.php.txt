<?php

declare(strict_types=1);

namespace HybridPHP\Core\Monitoring;

use Amp\Future;
use Psr\Log\LoggerInterface;
use function Amp\async;
use function Amp\delay;

/**
 * Metrics collector for performance monitoring
 */
class MetricsCollector
{
    private array $metrics = [];
    private array $counters = [];
    private array $histograms = [];
    private array $gauges = [];
    private ?LoggerInterface $logger;
    private bool $collecting = false;
    private array $config;

    public function __construct(?LoggerInterface $logger = null, array $config = [])
    {
        $this->logger = $logger;
        $this->config = array_merge([
            'collection_interval' => 10, // seconds
            'histogram_buckets' => [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0],
            'max_metrics' => 10000,
        ], $config);
    }

    /**
     * Start metrics collection
     */
    public function start(): Future
    {
        return async(function () {
            if ($this->collecting) {
                return;
            }

            $this->collecting = true;
            
            if ($this->logger) {
                $this->logger->info('Metrics collector started');
            }

            while ($this->collecting) {
                try {
                    $this->collectSystemMetrics();
                    $this->cleanupOldMetrics();
                } catch (\Throwable $e) {
                    if ($this->logger) {
                        $this->logger->error('Metrics collection failed', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                delay($this->config['collection_interval']);
            }
        });
    }

    /**
     * Stop metrics collection
     */
    public function stop(): void
    {
        $this->collecting = false;
        
        if ($this->logger) {
            $this->logger->info('Metrics collector stopped');
        }
    }

    /**
     * Increment counter metric
     */
    public function incrementCounter(string $name, array $labels = [], float $value = 1.0): void
    {
        $key = $this->getMetricKey($name, $labels);
        
        if (!isset($this->counters[$key])) {
            $this->counters[$key] = [
                'name' => $name,
                'labels' => $labels,
                'value' => 0,
                'created_at' => microtime(true),
            ];
        }
        
        $this->counters[$key]['value'] += $value;
        $this->counters[$key]['updated_at'] = microtime(true);
    }

    /**
     * Set gauge metric
     */
    public function setGauge(string $name, float $value, array $labels = []): void
    {
        $key = $this->getMetricKey($name, $labels);
        
        $this->gauges[$key] = [
            'name' => $name,
            'labels' => $labels,
            'value' => $value,
            'timestamp' => microtime(true),
        ];
    }

    /**
     * Observe histogram metric
     */
    public function observeHistogram(string $name, float $value, array $labels = []): void
    {
        $key = $this->getMetricKey($name, $labels);
        
        if (!isset($this->histograms[$key])) {
            $buckets = [];
            foreach ($this->config['histogram_buckets'] as $bucket) {
                $buckets[(string)$bucket] = 0;
            }
            
            $this->histograms[$key] = [
                'name' => $name,
                'labels' => $labels,
                'buckets' => $buckets,
                'count' => 0,
                'sum' => 0,
                'created_at' => microtime(true),
            ];
        }
        
        $histogram = &$this->histograms[$key];
        $histogram['count']++;
        $histogram['sum'] += $value;
        $histogram['updated_at'] = microtime(true);
        
        // Update buckets
        foreach ($this->config['histogram_buckets'] as $bucket) {
            if ($value <= $bucket) {
                $histogram['buckets'][(string)$bucket]++;
            }
        }
    }

    /**
     * Record timing metric
     */
    public function recordTiming(string $name, float $startTime, array $labels = []): void
    {
        $duration = microtime(true) - $startTime;
        $this->observeHistogram($name . '_duration_seconds', $duration, $labels);
    }

    /**
     * Get all metrics
     */
    public function getMetrics(): array
    {
        return [
            'counters' => $this->counters,
            'gauges' => $this->gauges,
            'histograms' => $this->histograms,
            'timestamp' => microtime(true),
        ];
    }

    /**
     * Get metrics in Prometheus format
     */
    public function getPrometheusMetrics(): string
    {
        $output = [];
        
        // Counters
        foreach ($this->counters as $counter) {
            $metricName = $this->sanitizeMetricName($counter['name']);
            $labels = $this->formatLabels($counter['labels']);
            $output[] = "# TYPE {$metricName} counter";
            $output[] = "{$metricName}{$labels} {$counter['value']}";
        }
        
        // Gauges
        foreach ($this->gauges as $gauge) {
            $metricName = $this->sanitizeMetricName($gauge['name']);
            $labels = $this->formatLabels($gauge['labels']);
            $output[] = "# TYPE {$metricName} gauge";
            $output[] = "{$metricName}{$labels} {$gauge['value']}";
        }
        
        // Histograms
        foreach ($this->histograms as $histogram) {
            $metricName = $this->sanitizeMetricName($histogram['name']);
            $labels = $this->formatLabels($histogram['labels']);
            
            $output[] = "# TYPE {$metricName} histogram";
            
            // Buckets
            foreach ($histogram['buckets'] as $bucket => $count) {
                $bucketLabels = $this->formatLabels(array_merge($histogram['labels'], ['le' => $bucket]));
                $output[] = "{$metricName}_bucket{$bucketLabels} {$count}";
            }
            
            // +Inf bucket
            $infLabels = $this->formatLabels(array_merge($histogram['labels'], ['le' => '+Inf']));
            $output[] = "{$metricName}_bucket{$infLabels} {$histogram['count']}";
            
            // Count and sum
            $output[] = "{$metricName}_count{$labels} {$histogram['count']}";
            $output[] = "{$metricName}_sum{$labels} {$histogram['sum']}";
        }
        
        return implode("\n", $output) . "\n";
    }

    /**
     * Get metrics in JSON format for ELK
     */
    public function getJsonMetrics(): array
    {
        $timestamp = date('c');
        $metrics = [];
        
        // Counters
        foreach ($this->counters as $counter) {
            $metrics[] = [
                '@timestamp' => $timestamp,
                'metric_type' => 'counter',
                'metric_name' => $counter['name'],
                'metric_value' => $counter['value'],
                'labels' => $counter['labels'],
            ];
        }
        
        // Gauges
        foreach ($this->gauges as $gauge) {
            $metrics[] = [
                '@timestamp' => $timestamp,
                'metric_type' => 'gauge',
                'metric_name' => $gauge['name'],
                'metric_value' => $gauge['value'],
                'labels' => $gauge['labels'],
            ];
        }
        
        // Histograms
        foreach ($this->histograms as $histogram) {
            $metrics[] = [
                '@timestamp' => $timestamp,
                'metric_type' => 'histogram',
                'metric_name' => $histogram['name'],
                'metric_count' => $histogram['count'],
                'metric_sum' => $histogram['sum'],
                'metric_avg' => $histogram['count'] > 0 ? $histogram['sum'] / $histogram['count'] : 0,
                'labels' => $histogram['labels'],
                'buckets' => $histogram['buckets'],
            ];
        }
        
        return $metrics;
    }

    /**
     * Clear all metrics
     */
    public function clear(): void
    {
        $this->counters = [];
        $this->gauges = [];
        $this->histograms = [];
        
        if ($this->logger) {
            $this->logger->info('Metrics cleared');
        }
    }

    /**
     * Get metric key for storage
     */
    private function getMetricKey(string $name, array $labels): string
    {
        ksort($labels);
        return $name . ':' . md5(serialize($labels));
    }

    /**
     * Sanitize metric name for Prometheus
     */
    private function sanitizeMetricName(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_:]/', '_', $name);
    }

    /**
     * Format labels for Prometheus
     */
    private function formatLabels(array $labels): string
    {
        if (empty($labels)) {
            return '';
        }
        
        $formatted = [];
        foreach ($labels as $key => $value) {
            $key = preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$key);
            $value = addslashes((string)$value);
            $formatted[] = "{$key}=\"{$value}\"";
        }
        
        return '{' . implode(',', $formatted) . '}';
    }

    /**
     * Collect system metrics
     */
    private function collectSystemMetrics(): void
    {
        // Memory metrics
        $this->setGauge('php_memory_usage_bytes', memory_get_usage(true));
        $this->setGauge('php_memory_peak_bytes', memory_get_peak_usage(true));
        
        $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
        if ($memoryLimit > 0) {
            $this->setGauge('php_memory_limit_bytes', $memoryLimit);
            $this->setGauge('php_memory_usage_ratio', memory_get_usage(true) / $memoryLimit);
        }

        // CPU load (if available)
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $this->setGauge('system_load_1m', $load[0]);
            $this->setGauge('system_load_5m', $load[1]);
            $this->setGauge('system_load_15m', $load[2]);
        }

        // Process metrics
        if (function_exists('getrusage')) {
            $usage = getrusage();
            $this->setGauge('process_cpu_user_seconds', $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1000000);
            $this->setGauge('process_cpu_system_seconds', $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1000000);
        }

        // Disk metrics
        $storagePath = __DIR__ . '/../../storage';
        if (is_dir($storagePath)) {
            $diskFree = disk_free_space($storagePath);
            $diskTotal = disk_total_space($storagePath);
            
            if ($diskFree !== false && $diskTotal !== false) {
                $this->setGauge('disk_free_bytes', $diskFree);
                $this->setGauge('disk_total_bytes', $diskTotal);
                $this->setGauge('disk_usage_ratio', ($diskTotal - $diskFree) / $diskTotal);
            }
        }
    }

    /**
     * Clean up old metrics to prevent memory leaks
     */
    private function cleanupOldMetrics(): void
    {
        $totalMetrics = count($this->counters) + count($this->gauges) + count($this->histograms);
        
        if ($totalMetrics > $this->config['max_metrics']) {
            $cutoff = microtime(true) - 3600; // Remove metrics older than 1 hour
            
            $this->counters = array_filter($this->counters, function ($metric) use ($cutoff) {
                return ($metric['updated_at'] ?? $metric['created_at']) > $cutoff;
            });
            
            $this->gauges = array_filter($this->gauges, function ($metric) use ($cutoff) {
                return $metric['timestamp'] > $cutoff;
            });
            
            $this->histograms = array_filter($this->histograms, function ($metric) use ($cutoff) {
                return ($metric['updated_at'] ?? $metric['created_at']) > $cutoff;
            });
        }
    }

    /**
     * Parse memory limit string to bytes
     */
    private function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1') {
            return 0; // No limit
        }

        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int) $limit;

        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }
}