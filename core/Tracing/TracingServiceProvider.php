<?php

declare(strict_types=1);

namespace HybridPHP\Core\Tracing;

use HybridPHP\Core\Container;
use HybridPHP\Core\Tracing\Exporter\ConsoleExporter;
use HybridPHP\Core\Tracing\Exporter\ExporterInterface;
use HybridPHP\Core\Tracing\Exporter\JaegerExporter;
use HybridPHP\Core\Tracing\Exporter\OtlpExporter;
use HybridPHP\Core\Tracing\Exporter\ZipkinExporter;
use HybridPHP\Core\Tracing\Middleware\DatabaseTracingMiddleware;
use HybridPHP\Core\Tracing\Middleware\TracingMiddleware;
use HybridPHP\Core\Tracing\Propagation\CompositePropagator;
use HybridPHP\Core\Tracing\Propagation\PropagatorInterface;
use Psr\Log\LoggerInterface;

/**
 * Service provider for distributed tracing
 */
class TracingServiceProvider
{
    private Container $container;
    private array $config;

    public function __construct(Container $container, array $config = [])
    {
        $this->container = $container;
        $this->config = $config;
    }

    /**
     * Register tracing services
     */
    public function register(): void
    {
        // Register propagator
        $this->container->singleton(PropagatorInterface::class, function () {
            return CompositePropagator::createDefault();
        });

        // Register exporter based on configuration
        $this->container->singleton(ExporterInterface::class, function () {
            return $this->createExporter();
        });

        // Register main tracer
        $this->container->singleton(Tracer::class, function () {
            $logger = $this->container->has(LoggerInterface::class)
                ? $this->container->get(LoggerInterface::class)
                : null;

            return new Tracer(
                $this->config['service_name'] ?? 'hybridphp',
                $this->container->get(ExporterInterface::class),
                $this->container->get(PropagatorInterface::class),
                $logger,
                $this->config['tracer'] ?? []
            );
        });

        // Register TracingInterface alias
        $this->container->singleton(TracingInterface::class, function () {
            return $this->container->get(Tracer::class);
        });

        // Register HTTP tracing middleware
        $this->container->singleton(TracingMiddleware::class, function () {
            return new TracingMiddleware(
                $this->container->get(Tracer::class),
                $this->config['http'] ?? []
            );
        });

        // Register database tracing middleware
        $this->container->singleton(DatabaseTracingMiddleware::class, function () {
            return new DatabaseTracingMiddleware(
                $this->container->get(Tracer::class),
                $this->config['database'] ?? []
            );
        });
    }

    /**
     * Boot tracing services
     */
    public function boot(): void
    {
        if ($this->config['enabled'] ?? true) {
            $tracer = $this->container->get(Tracer::class);
            $tracer->start();
        }
    }

    /**
     * Create exporter based on configuration
     */
    private function createExporter(): ExporterInterface
    {
        $exporterType = $this->config['exporter']['type'] ?? 'console';
        $exporterConfig = $this->config['exporter'] ?? [];

        $logger = $this->container->has(LoggerInterface::class)
            ? $this->container->get(LoggerInterface::class)
            : null;

        return match ($exporterType) {
            'jaeger' => new JaegerExporter(
                $exporterConfig['endpoint'] ?? 'http://localhost:14268/api/traces',
                $this->config['service_name'] ?? 'hybridphp',
                $exporterConfig['tags'] ?? [],
                $logger
            ),
            'zipkin' => new ZipkinExporter(
                $exporterConfig['endpoint'] ?? 'http://localhost:9411/api/v2/spans',
                $this->config['service_name'] ?? 'hybridphp',
                $exporterConfig['local_endpoint'] ?? null,
                $logger
            ),
            'otlp' => new OtlpExporter(
                $exporterConfig['endpoint'] ?? 'http://localhost:4318/v1/traces',
                $this->config['service_name'] ?? 'hybridphp',
                $exporterConfig['resource_attributes'] ?? [],
                $exporterConfig['headers'] ?? [],
                $logger
            ),
            default => new ConsoleExporter($exporterConfig['pretty_print'] ?? true),
        };
    }
}
