<?php

declare(strict_types=1);

namespace HybridPHP\Core\Debug;

use HybridPHP\Core\Application;
use HybridPHP\Core\Container;
use Psr\Log\LoggerInterface;

/**
 * Debug service provider for registering debugging tools
 */
class DebugServiceProvider
{
    private Container $container;
    private array $config;

    public function __construct(Container $container, array $config = [])
    {
        $this->container = $container;
        $this->config = array_merge([
            'debug' => false,
            'profiler_enabled' => true,
            'coroutine_debugger_enabled' => true,
            'query_analyzer_enabled' => true,
            'error_handler_enabled' => true,
            'slow_query_threshold' => 0.1,
            'slow_coroutine_threshold' => 1.0,
            'max_queries' => 1000,
            'collect_stack_traces' => true,
            'show_source_code' => true,
            'source_code_lines' => 10,
        ], $config);
    }

    /**
     * Register debug services
     */
    public function register(): void
    {
        $this->registerPerformanceProfiler();
        $this->registerCoroutineDebugger();
        $this->registerQueryAnalyzer();
        $this->registerDebugErrorHandler();
    }

    /**
     * Register performance profiler
     */
    private function registerPerformanceProfiler(): void
    {
        $this->container->singleton(PerformanceProfiler::class, function () {
            $logger = $this->container->has(LoggerInterface::class) 
                ? $this->container->get(LoggerInterface::class) 
                : null;
            
            $profiler = new PerformanceProfiler($logger);
            $profiler->setEnabled($this->config['profiler_enabled']);
            
            return $profiler;
        });
    }

    /**
     * Register coroutine debugger
     */
    private function registerCoroutineDebugger(): void
    {
        $this->container->singleton(CoroutineDebugger::class, function () {
            $logger = $this->container->has(LoggerInterface::class) 
                ? $this->container->get(LoggerInterface::class) 
                : null;
            
            $debugger = new CoroutineDebugger($logger);
            $debugger->setEnabled($this->config['coroutine_debugger_enabled']);
            $debugger->setSlowThreshold($this->config['slow_coroutine_threshold']);
            $debugger->setStackCollection(
                $this->config['collect_stack_traces'],
                20
            );
            
            return $debugger;
        });
    }

    /**
     * Register query analyzer
     */
    private function registerQueryAnalyzer(): void
    {
        $this->container->singleton(QueryAnalyzer::class, function () {
            $logger = $this->container->has(LoggerInterface::class) 
                ? $this->container->get(LoggerInterface::class) 
                : null;
            
            $analyzer = new QueryAnalyzer($logger);
            $analyzer->setEnabled($this->config['query_analyzer_enabled']);
            $analyzer->setSlowQueryThreshold($this->config['slow_query_threshold']);
            
            return $analyzer;
        });
    }

    /**
     * Register debug error handler
     */
    private function registerDebugErrorHandler(): void
    {
        $this->container->singleton(DebugErrorHandler::class, function () {
            $logger = $this->container->has(LoggerInterface::class) 
                ? $this->container->get(LoggerInterface::class) 
                : null;
            
            $profiler = $this->container->get(PerformanceProfiler::class);
            
            $errorHandler = new DebugErrorHandler($logger, $this->config['debug'], $profiler);
            $errorHandler->setSourceCodeOptions(
                $this->config['show_source_code'],
                $this->config['source_code_lines']
            );
            $errorHandler->setStackTraceCollection($this->config['collect_stack_traces']);
            
            return $errorHandler;
        });
    }

    /**
     * Boot debug services
     */
    public function boot(): void
    {
        if ($this->config['error_handler_enabled']) {
            $this->setupErrorHandling();
        }

        if ($this->config['profiler_enabled']) {
            $this->setupProfiling();
        }

        if ($this->config['coroutine_debugger_enabled']) {
            $this->setupCoroutineDebugging();
        }

        if ($this->config['query_analyzer_enabled']) {
            $this->setupQueryAnalysis();
        }
    }

    /**
     * Setup error handling
     */
    private function setupErrorHandling(): void
    {
        $errorHandler = $this->container->get(DebugErrorHandler::class);
        
        // Register error handlers
        set_error_handler([$errorHandler, 'handleError']);
        set_exception_handler([$errorHandler, 'handleException']);
        register_shutdown_function([$errorHandler, 'handleShutdown']);
    }

    /**
     * Setup profiling
     */
    private function setupProfiling(): void
    {
        $profiler = $this->container->get(PerformanceProfiler::class);
        
        // Start application profiling
        $profiler->startTimer('application_boot');
        $profiler->recordMemorySnapshot('application_start');
        
        // Register shutdown handler to stop profiling
        register_shutdown_function(function () use ($profiler) {
            $profiler->stopTimer('application_boot');
            $profiler->recordMemorySnapshot('application_end');
        });
    }

    /**
     * Setup coroutine debugging
     */
    private function setupCoroutineDebugging(): void
    {
        $debugger = $this->container->get(CoroutineDebugger::class);
        
        // Start monitoring in background
        if ($this->container->has(Application::class)) {
            $app = $this->container->get(Application::class);
            // The monitoring will be started when the application runs
        }
    }

    /**
     * Setup query analysis
     */
    private function setupQueryAnalysis(): void
    {
        $analyzer = $this->container->get(QueryAnalyzer::class);
        
        // Query analysis will be integrated with the database layer
        // This is typically done by hooking into the database connection
    }

    /**
     * Get debug configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Update configuration
     */
    public function updateConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    /**
     * Enable/disable debugging
     */
    public function setDebugMode(bool $enabled): void
    {
        $this->config['debug'] = $enabled;
        
        if ($this->container->has(DebugErrorHandler::class)) {
            $this->container->get(DebugErrorHandler::class)->setDebug($enabled);
        }
    }

    /**
     * Get debug status
     */
    public function getDebugStatus(): array
    {
        return [
            'debug_mode' => $this->config['debug'],
            'profiler_enabled' => $this->config['profiler_enabled'],
            'coroutine_debugger_enabled' => $this->config['coroutine_debugger_enabled'],
            'query_analyzer_enabled' => $this->config['query_analyzer_enabled'],
            'error_handler_enabled' => $this->config['error_handler_enabled'],
            'services_registered' => [
                'profiler' => $this->container->has(PerformanceProfiler::class),
                'coroutine_debugger' => $this->container->has(CoroutineDebugger::class),
                'query_analyzer' => $this->container->has(QueryAnalyzer::class),
                'error_handler' => $this->container->has(DebugErrorHandler::class),
            ],
        ];
    }
}