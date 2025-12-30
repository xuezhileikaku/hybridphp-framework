<?php

declare(strict_types=1);

namespace HybridPHP\Core\Tracing\Propagation;

use HybridPHP\Core\Tracing\SpanContextInterface;

/**
 * Composite propagator that combines multiple propagators
 * 
 * Useful for supporting multiple trace context formats simultaneously
 */
class CompositePropagator implements PropagatorInterface
{
    /** @var PropagatorInterface[] */
    private array $propagators;

    /**
     * @param PropagatorInterface[] $propagators
     */
    public function __construct(array $propagators = [])
    {
        $this->propagators = $propagators;
    }

    /**
     * Create a default composite propagator with common formats
     */
    public static function createDefault(): self
    {
        return new self([
            new W3CTraceContextPropagator(),
            new B3Propagator(),
            new JaegerPropagator(),
        ]);
    }

    /**
     * Add a propagator
     */
    public function addPropagator(PropagatorInterface $propagator): self
    {
        $this->propagators[] = $propagator;
        return $this;
    }

    public function fields(): array
    {
        $fields = [];
        
        foreach ($this->propagators as $propagator) {
            $fields = array_merge($fields, $propagator->fields());
        }

        return array_unique($fields);
    }

    public function inject(SpanContextInterface $context, array &$carrier): void
    {
        foreach ($this->propagators as $propagator) {
            $propagator->inject($context, $carrier);
        }
    }

    public function extract(array $carrier): ?SpanContextInterface
    {
        foreach ($this->propagators as $propagator) {
            $context = $propagator->extract($carrier);
            
            if ($context !== null && $context->isValid()) {
                return $context;
            }
        }

        return null;
    }
}
