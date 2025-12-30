<?php

declare(strict_types=1);

namespace HybridPHP\Core\Tracing\Exporter;

use Amp\Future;
use HybridPHP\Core\Tracing\SpanInterface;
use function Amp\async;

/**
 * Console exporter for debugging
 * 
 * Outputs spans to stdout in a human-readable format
 */
class ConsoleExporter implements ExporterInterface
{
    private bool $prettyPrint;
    private bool $shutdown = false;

    public function __construct(bool $prettyPrint = true)
    {
        $this->prettyPrint = $prettyPrint;
    }

    public function export(array $spans): Future
    {
        return async(function () use ($spans) {
            if ($this->shutdown || empty($spans)) {
                return true;
            }

            foreach ($spans as $span) {
                $this->printSpan($span);
            }

            return true;
        });
    }

    public function flush(): Future
    {
        return async(fn() => true);
    }

    public function shutdown(): Future
    {
        return async(function () {
            $this->shutdown = true;
            return true;
        });
    }

    /**
     * Print span to console
     */
    private function printSpan(SpanInterface $span): void
    {
        $data = $span->toArray();

        if ($this->prettyPrint) {
            $this->printPretty($data);
        } else {
            echo json_encode($data) . PHP_EOL;
        }
    }

    /**
     * Print span in pretty format
     */
    private function printPretty(array $data): void
    {
        $duration = $data['duration'] !== null 
            ? sprintf('%.3fms', $data['duration'] * 1000) 
            : 'N/A';

        $status = $data['status']['code'];
        $statusIcon = match ($status) {
            'ok' => '✓',
            'error' => '✗',
            default => '○',
        };

        echo sprintf(
            "[%s] %s %s (trace=%s, span=%s, duration=%s)\n",
            date('Y-m-d H:i:s', (int) $data['start_time']),
            $statusIcon,
            $data['operation_name'],
            substr($data['trace_id'], 0, 8) . '...',
            substr($data['span_id'], 0, 8) . '...',
            $duration
        );

        // Print attributes
        if (!empty($data['attributes'])) {
            echo "  Attributes:\n";
            foreach ($data['attributes'] as $key => $value) {
                $valueStr = is_array($value) ? json_encode($value) : (string) $value;
                echo sprintf("    %s: %s\n", $key, $valueStr);
            }
        }

        // Print events
        if (!empty($data['events'])) {
            echo "  Events:\n";
            foreach ($data['events'] as $event) {
                echo sprintf(
                    "    [%s] %s\n",
                    date('H:i:s', (int) $event['timestamp']),
                    $event['name']
                );
            }
        }

        echo "\n";
    }
}
