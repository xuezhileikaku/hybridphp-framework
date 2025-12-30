<?php
namespace HybridPHP\Core;

use HybridPHP\Core\Container;
use HybridPHP\Core\EventEmitter;
use HybridPHP\Core\Server\ServerManager;
use HybridPHP\Core\ConfigManager;
use HybridPHP\Core\ErrorHandler;
use Psr\Log\LoggerInterface;
use Amp\Future;
use Amp\DeferredFuture;
use function Amp\async;
use function Amp\delay;

class Application
{
    public Container $container;
    public EventEmitter $event;
    public ServerManager $serverManager;
    public ConfigManager $config;
    public ErrorHandler $errorHandler;
    
    protected bool $running = false;
    protected bool $shuttingDown = false;
    protected array $lifecycleHooks = [];
    protected array $signalHandlers = [];
    protected array $coroutines = [];
    protected ?DeferredFuture $shutdownDeferred = null;

    public function __construct(string $basePath = '', array $serverConfigs = [])
    {
        $this->container = new Container();
        $this->event = new EventEmitter();
        $this->config = new ConfigManager($basePath ? $basePath . '/config' : '');
        $this->errorHandler = new ErrorHandler();
        
        // Register core services in container
        $this->registerCoreServices();
        
        $this->serverManager = new ServerManager(
            $serverConfigs,
            $this->container,
            $this->event
        );

        // Setup signal handlers for graceful shutdown
        $this->setupSignalHandlers();
        
        // Setup error handling
        $this->setupErrorHandling();
        
        // Initialize AMPHP event loop integration
        $this->initializeEventLoop();
    }

    /**
     * Register core services in the container
     */
    protected function registerCoreServices(): void
    {
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(EventEmitter::class, $this->event);
        $this->container->instance(ConfigManager::class, $this->config);
        $this->container->instance(Application::class, $this);
        
        // Register logging system
        $this->registerLoggingSystem();
        
        // Register health check system
        $this->registerHealthCheckSystem();
    }

    /**
     * Register logging system
     */
    protected function registerLoggingSystem(): void
    {
        // Load logging configuration
        $loggingConfig = $this->config->get('logging', []);
        
        if (!empty($loggingConfig)) {
            $loggingServiceProvider = new \HybridPHP\Core\Logging\LoggingServiceProvider($this->container, $loggingConfig);
            $loggingServiceProvider->register();
            
            // Add lifecycle hook to boot logging services after application starts
            $this->addLifecycleHook('afterStart', function() use ($loggingServiceProvider) {
                $loggingServiceProvider->boot();
            });
            
            // Add lifecycle hook to shutdown logging services
            $this->addLifecycleHook('beforeShutdown', function() use ($loggingServiceProvider) {
                $loggingServiceProvider->shutdown()->await();
            });
        }
    }

    /**
     * Register health check system
     */
    protected function registerHealthCheckSystem(): void
    {
        // Load health check configuration
        $healthConfig = $this->config->get('health', []);
        
        if (!empty($healthConfig) && ($healthConfig['enabled'] ?? true)) {
            $healthServiceProvider = new \HybridPHP\Core\Health\HealthServiceProvider($this->container, $healthConfig);
            $healthServiceProvider->register();
            
            // Add lifecycle hook to boot health services after application starts
            $this->addLifecycleHook('afterStart', function() use ($healthServiceProvider) {
                $healthServiceProvider->boot();
            });
        }
    }

    /**
     * Setup signal handlers for graceful shutdown
     */
    protected function setupSignalHandlers(): void
    {
        if (function_exists('pcntl_signal')) {
            // Graceful shutdown signals
            pcntl_signal(SIGTERM, [$this, 'handleShutdownSignal']);
            pcntl_signal(SIGINT, [$this, 'handleShutdownSignal']);
            
            // Reload signal
            pcntl_signal(SIGUSR1, [$this, 'handleReloadSignal']);
            
            // Enable signal handling
            pcntl_async_signals(true);
        }
    }

    /**
     * Setup error handling
     */
    protected function setupErrorHandling(): void
    {
        set_error_handler([$this->errorHandler, 'handleError']);
        set_exception_handler([$this->errorHandler, 'handleException']);
        register_shutdown_function([$this->errorHandler, 'handleShutdown']);
    }

    /**
     * Add a lifecycle hook
     */
    public function addLifecycleHook(string $event, callable $callback): void
    {
        if (!isset($this->lifecycleHooks[$event])) {
            $this->lifecycleHooks[$event] = [];
        }
        $this->lifecycleHooks[$event][] = $callback;
    }

    /**
     * Execute lifecycle hooks
     */
    protected function executeLifecycleHooks(string $event): void
    {
        if (isset($this->lifecycleHooks[$event])) {
            foreach ($this->lifecycleHooks[$event] as $callback) {
                try {
                    $callback($this);
                } catch (\Throwable $e) {
                    $this->errorHandler->handleException($e);
                }
            }
        }
        
        // Also emit event
        $this->event->emit("app.{$event}", [$this]);
    }

    /**
     * Start the application
     */
    public function run(): void
    {
        if ($this->running) {
            throw new \RuntimeException('Application is already running');
        }

        try {
            $this->running = true;
            
            // Execute before start hooks
            $this->executeLifecycleHooks('beforeStart');
            
            // Start all servers
            $this->serverManager->startAll();
            
            // Execute after start hooks
            $this->executeLifecycleHooks('afterStart');
            
            echo "HybridPHP Framework started successfully\n";
            
            // Keep the application running
            // In AMPHP v3, we don't use Loop::run() directly
            // The servers will handle the event loop
            
        } catch (\Throwable $e) {
            $this->errorHandler->handleException($e);
            $this->shutdown();
        }
    }

    /**
     * Graceful shutdown
     */
    public function shutdown(): void
    {
        if ($this->shuttingDown) {
            return;
        }

        $this->shuttingDown = true;
        
        echo "Shutting down HybridPHP Framework...\n";
        
        // Execute before shutdown hooks
        $this->executeLifecycleHooks('beforeShutdown');
        
        // Stop all servers
        $this->serverManager->stopAll();
        
        // Execute after shutdown hooks
        $this->executeLifecycleHooks('afterShutdown');
        
        $this->running = false;
        
        echo "HybridPHP Framework shutdown complete\n";
        
        // In AMPHP v3, event loop management is handled differently
    }

    /**
     * Reload configuration and restart services
     */
    public function reload(): void
    {
        echo "Reloading HybridPHP Framework...\n";
        
        // Execute before reload hooks
        $this->executeLifecycleHooks('beforeReload');
        
        // Reload configuration
        $this->config->reload();
        
        // Restart servers
        $this->serverManager->restart();
        
        // Execute after reload hooks
        $this->executeLifecycleHooks('afterReload');
        
        echo "HybridPHP Framework reloaded successfully\n";
    }

    /**
     * Handle shutdown signals
     */
    public function handleShutdownSignal(int $signal): void
    {
        echo "\nReceived signal {$signal}, initiating graceful shutdown...\n";
        
        // Use async to prevent blocking
        async(function() {
            delay(0.1); // Small delay to allow current operations to complete
            $this->shutdown();
        });
    }

    /**
     * Handle reload signal
     */
    public function handleReloadSignal(int $signal): void
    {
        echo "\nReceived reload signal {$signal}, reloading configuration...\n";
        
        async(function() {
            delay(0.1);
            $this->reload();
        });
    }

    /**
     * Check if application is running
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Check if application is shutting down
     */
    public function isShuttingDown(): bool
    {
        return $this->shuttingDown;
    }

    /**
     * Get application version
     */
    public function getVersion(): string
    {
        return '1.0.0-alpha';
    }

    /**
     * Get application environment
     */
    public function getEnvironment(): string
    {
        return $this->config->get('app.env', 'production');
    }

    /**
     * Check if application is in debug mode
     */
    public function isDebug(): bool
    {
        return $this->config->get('app.debug', false);
    }

    /**
     * Initialize AMPHP event loop integration
     */
    protected function initializeEventLoop(): void
    {
        // AMPHP v3 doesn't use Loop class in the same way
        // We'll set up periodic tasks when the application runs
        // For now, just initialize the event system
        $this->event->emit('app.initialized', [$this]);
    }

    /**
     * Async version of reload
     */
    public function reloadAsync(): Future
    {
        return async(function() {
            echo "Reloading HybridPHP Framework (async)...\n";
            
            // Execute before reload hooks
            $this->executeLifecycleHooksAsync('beforeReload');
            
            // Reload configuration
            $this->config->reload();
            
            // Restart servers
            $this->serverManager->restartAsync();
            
            // Execute after reload hooks
            $this->executeLifecycleHooksAsync('afterReload');
            
            echo "HybridPHP Framework reloaded successfully (async)\n";
        });
    }

    /**
     * Execute lifecycle hooks asynchronously
     */
    protected function executeLifecycleHooksAsync(string $event): void
    {
        if (isset($this->lifecycleHooks[$event])) {
            foreach ($this->lifecycleHooks[$event] as $callback) {
                try {
                    $result = $callback($this);
                    // For now, we don't await async results in this simplified version
                } catch (\Throwable $e) {
                    $this->errorHandler->handleException($e);
                }
            }
        }
        
        // Also emit event
        $this->event->emit("app.{$event}", [$this]);
    }

    /**
     * Perform health check
     */
    protected function performHealthCheck(): void
    {
        try {
            // Check memory usage
            $memoryUsage = memory_get_usage(true);
            $memoryLimit = ini_get('memory_limit');
            
            if ($memoryLimit !== '-1') {
                $memoryLimitBytes = $this->parseMemoryLimit($memoryLimit);
                $memoryUsagePercent = ($memoryUsage / $memoryLimitBytes) * 100;
                
                if ($memoryUsagePercent > 80) {
                    echo "Warning: High memory usage detected ({$memoryUsagePercent}%)\n";
                    $this->event->emit('app.highMemoryUsage', [$memoryUsagePercent]);
                }
            }
            
            // Emit health check event
            $this->event->emit('app.healthCheck', [
                'memory' => $memoryUsage,
                'timestamp' => time()
            ]);
            
        } catch (\Throwable $e) {
            $this->errorHandler->handleException($e);
        }
    }

    /**
     * Parse memory limit string to bytes
     */
    protected function parseMemoryLimit(string $limit): int
    {
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

    /**
     * Run a coroutine and track it
     */
    public function runCoroutine(callable $coroutine, string $name = null): Future
    {
        $future = async($coroutine);
        
        if ($name) {
            $this->coroutines[$name] = $future;
        }
        
        // Clean up completed coroutines
        $future->finally(function() use ($name) {
            if ($name && isset($this->coroutines[$name])) {
                unset($this->coroutines[$name]);
            }
        });
        
        return $future;
    }

    /**
     * Get running coroutines
     */
    public function getRunningCoroutines(): array
    {
        return array_keys($this->coroutines);
    }

    /**
     * Wait for all coroutines to complete
     */
    public function waitForCoroutines(int $timeout = 30): Future
    {
        return async(function() use ($timeout) {
            $start = time();
            
            while (!empty($this->coroutines) && (time() - $start) < $timeout) {
                delay(0.1); // 100ms delay
            }
            
            if (!empty($this->coroutines)) {
                echo "Warning: Some coroutines did not complete within timeout\n";
            }
        });
    }

    /**
     * Graceful shutdown with async support
     */
    public function shutdownAsync(): Future
    {
        if ($this->shutdownDeferred) {
            return $this->shutdownDeferred->getFuture();
        }

        $this->shutdownDeferred = new DeferredFuture();

        return async(function() {
            if ($this->shuttingDown) {
                $this->shutdownDeferred->complete();
                return;
            }

            $this->shuttingDown = true;
            
            echo "Shutting down HybridPHP Framework (async)...\n";
            
            // Execute before shutdown hooks
            $this->executeLifecycleHooksAsync('beforeShutdown');
            
            // Wait for running coroutines to complete
            $this->waitForCoroutines(30);
            
            // Stop all servers
            $this->serverManager->stopAllAsync();
            
            // Execute after shutdown hooks
            $this->executeLifecycleHooksAsync('afterShutdown');
            
            $this->running = false;
            
            echo "HybridPHP Framework shutdown complete (async)\n";
            
            $this->shutdownDeferred->complete();
        });
    }
}
