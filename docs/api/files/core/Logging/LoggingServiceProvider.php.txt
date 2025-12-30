<?php
namespace HybridPHP\Core\Logging;

use HybridPHP\Core\Container;
use HybridPHP\Core\ConfigManager;
use Psr\Log\LoggerInterface;

/**
 * Logging Service Provider for registering logging services
 */
class LoggingServiceProvider
{
    private Container $container;
    private array $config;

    public function __construct(Container $container, array $config = [])
    {
        $this->container = $container;
        $this->config = $config;
    }

    /**
     * Register logging services
     */
    public function register(): void
    {
        // Register LogManager
        $this->container->singleton(LogManager::class, function($container) {
            $configManager = $container->get(ConfigManager::class);
            return new LogManager($configManager);
        });

        // Register default logger
        $this->container->singleton(LoggerInterface::class, function($container) {
            $logManager = $container->get(LogManager::class);
            return $logManager->getLogger();
        });

        // Register AsyncLogger
        $this->container->singleton(AsyncLogger::class, function($container) {
            $logManager = $container->get(LogManager::class);
            return $logManager->channel();
        });

        // Register DistributedTracing
        $this->container->singleton(DistributedTracing::class, function() {
            return new DistributedTracing();
        });

        // Register LogArchiver
        $this->container->singleton(LogArchiver::class, function($container) {
            $configManager = $container->get(ConfigManager::class);
            $archiveConfig = $configManager->get('logging.archive', []);
            return new LogArchiver($archiveConfig);
        });
    }

    /**
     * Boot logging services
     */
    public function boot(): void
    {
        // Start log archiver if enabled
        if ($this->config['archive']['enabled'] ?? false) {
            $archiver = $this->container->get(LogArchiver::class);
            $interval = $this->config['archive']['interval'] ?? 3600;
            $archiver->startAutoArchiving($interval);
        }

        // Initialize distributed tracing
        $this->initializeDistributedTracing();
    }

    /**
     * Initialize distributed tracing
     */
    private function initializeDistributedTracing(): void
    {
        // Extract trace context from HTTP headers if available
        if (isset($_SERVER['HTTP_X_TRACE_ID'])) {
            $headers = [
                'x-trace-id' => $_SERVER['HTTP_X_TRACE_ID'],
                'x-span-id' => $_SERVER['HTTP_X_SPAN_ID'] ?? null,
                'x-parent-span-id' => $_SERVER['HTTP_X_PARENT_SPAN_ID'] ?? null,
            ];
            
            DistributedTracing::extractFromHeaders($headers);
        } elseif (isset($_SERVER['HTTP_TRACEPARENT'])) {
            $headers = ['traceparent' => $_SERVER['HTTP_TRACEPARENT']];
            DistributedTracing::extractFromHeaders($headers);
        } else {
            // Start new trace for this request
            DistributedTracing::startTrace('http_request');
        }

        // Set request context
        DistributedTracing::setTag('http.method', $_SERVER['REQUEST_METHOD'] ?? 'CLI');
        DistributedTracing::setTag('http.url', $_SERVER['REQUEST_URI'] ?? 'cli');
        DistributedTracing::setTag('user_agent', $_SERVER['HTTP_USER_AGENT'] ?? 'cli');
    }

    /**
     * Create logger with tracing context
     */
    public function createTracingLogger(string $channel = null): AsyncLogger
    {
        $logManager = $this->container->get(LogManager::class);
        $logger = $logManager->channel($channel);
        
        // Set tracing context
        $tracingContext = DistributedTracing::getContext();
        $logger->setContext($tracingContext);
        
        if ($tracingContext['trace_id']) {
            $logger->setTraceId($tracingContext['trace_id']);
        }
        
        return $logger;
    }

    /**
     * Get logging statistics
     */
    public function getStats(): array
    {
        $logManager = $this->container->get(LogManager::class);
        $archiver = $this->container->get(LogArchiver::class);
        
        return [
            'loggers' => $logManager->getStats(),
            'archiver' => $archiver->getStats(),
            'tracing' => [
                'trace_id' => DistributedTracing::getTraceId(),
                'span_id' => DistributedTracing::getSpanId(),
                'spans_count' => count(DistributedTracing::getSpans()),
            ],
        ];
    }

    /**
     * Shutdown logging services
     */
    public function shutdown(): \Amp\Future
    {
        return \Amp\async(function() {
            $logManager = $this->container->get(LogManager::class);
            $logManager->stopAll()->await();
            
            // Finish current span
            DistributedTracing::finishSpan(['shutdown' => true]);
        });
    }
}