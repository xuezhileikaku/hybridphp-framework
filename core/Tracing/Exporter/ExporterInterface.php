<?php

declare(strict_types=1);

namespace HybridPHP\Core\Tracing\Exporter;

use Amp\Future;
use HybridPHP\Core\Tracing\SpanInterface;

/**
 * Interface for span exporters
 * 
 * Exporters send completed spans to tracing backends
 */
interface ExporterInterface
{
    /**
     * Export a batch of spans
     * 
     * @param SpanInterface[] $spans
     */
    public function export(array $spans): Future;

    /**
     * Force flush any pending spans
     */
    public function flush(): Future;

    /**
     * Shutdown the exporter
     */
    public function shutdown(): Future;
}
