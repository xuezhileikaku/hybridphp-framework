<?php

declare(strict_types=1);

namespace HybridPHP\Core\Tracing\Propagation;

use HybridPHP\Core\Tracing\SpanContextInterface;

/**
 * Interface for trace context propagation
 * 
 * Propagators handle injecting and extracting trace context from carriers
 */
interface PropagatorInterface
{
    /**
     * Get the fields used by this propagator
     */
    public function fields(): array;

    /**
     * Inject trace context into carrier
     */
    public function inject(SpanContextInterface $context, array &$carrier): void;

    /**
     * Extract trace context from carrier
     */
    public function extract(array $carrier): ?SpanContextInterface;
}
