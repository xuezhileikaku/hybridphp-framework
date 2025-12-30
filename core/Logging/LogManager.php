<?php
namespace HybridPHP\Core\Logging;

use HybridPHP\Core\ConfigManager;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Amp\Future;
use function Amp\async;

/**
 * Log Manager for handling multiple logging channels and configurations
 */
class LogManager
{
    private ConfigManager $config;
    private array $loggers = [];
    private array $channels = [];
    private string $defaultChannel;

    public function __construct(ConfigManager $config)
    {
        $this->config = $config;
        $this->loadConfiguration();
    }

    /**
     * Load logging configuration
     */
    private function loadConfiguration(): void
    {
        $loggingConfig = $this->config->get('logging', []);
        $this->defaultChannel = $loggingConfig['default'] ?? 'file';
        $this->channels = $loggingConfig['channels'] ?? [];
    }

    /**
     * Get logger for a specific channel
     */
    public function channel(string $name = null): LoggerInterface
    {
        $name = $name ?? $this->defaultChannel;

        if (!isset($this->loggers[$name])) {
            $this->loggers[$name] = $this->createLogger($name);
        }

        return $this->loggers[$name];
    }

    /**
     * Get the default logger
     */
    public function getLogger(): LoggerInterface
    {
        return $this->channel();
    }

    /**
     * Create a logger for a specific channel
     */
    private function createLogger(string $channelName): AsyncLogger
    {
        $channelConfig = $this->channels[$channelName] ?? [];
        
        if (empty($channelConfig)) {
            throw new \InvalidArgumentException("Logging channel '{$channelName}' is not configured");
        }

        $asyncConfig = $this->config->get('logging.async', []);
        
        $logger = new AsyncLogger(
            $channelName,
            $asyncConfig['buffer_size'] ?? 1000,
            $asyncConfig['flush_interval'] ?? 5.0,
            $asyncConfig['enabled'] ?? true
        );

        $this->configureLogger($logger, $channelConfig);

        return $logger;
    }

    /**
     * Configure logger with handlers based on channel configuration
     */
    private function configureLogger(AsyncLogger $logger, array $config): void
    {
        $driver = $config['driver'] ?? 'file';

        switch ($driver) {
            case 'file':
                $this->addFileHandler($logger, $config);
                break;
            case 'daily':
                $this->addDailyHandler($logger, $config);
                break;
            case 'syslog':
                $this->addSyslogHandler($logger, $config);
                break;
            case 'stderr':
                $this->addStderrHandler($logger, $config);
                break;
            case 'stack':
                $this->addStackHandlers($logger, $config);
                break;
            case 'elk':
                $this->addElkHandler($logger, $config);
                break;
            case 'kafka':
                $this->addKafkaHandler($logger, $config);
                break;
            default:
                throw new \InvalidArgumentException("Unsupported logging driver: {$driver}");
        }
    }

    /**
     * Add file handler
     */
    private function addFileHandler(AsyncLogger $logger, array $config): void
    {
        $path = $config['path'] ?? 'storage/logs/app.log';
        $level = $this->parseLogLevel($config['level'] ?? 'debug');

        $handler = new StreamHandler($path, $level);
        $handler->setFormatter(new JsonFormatter());
        
        $logger->addHandler($handler);
    }

    /**
     * Add daily rotating file handler
     */
    private function addDailyHandler(AsyncLogger $logger, array $config): void
    {
        $path = $config['path'] ?? 'storage/logs/app.log';
        $level = $this->parseLogLevel($config['level'] ?? 'debug');
        $maxFiles = $config['days'] ?? 14;

        $handler = new RotatingFileHandler($path, $maxFiles, $level);
        $handler->setFormatter(new JsonFormatter());
        
        $logger->addHandler($handler);
    }

    /**
     * Add syslog handler
     */
    private function addSyslogHandler(AsyncLogger $logger, array $config): void
    {
        $level = $this->parseLogLevel($config['level'] ?? 'debug');
        $facility = $config['facility'] ?? LOG_USER;

        $handler = new SyslogHandler('hybridphp', $facility, $level);
        $handler->setFormatter(new JsonFormatter());
        
        $logger->addHandler($handler);
    }

    /**
     * Add stderr handler
     */
    private function addStderrHandler(AsyncLogger $logger, array $config): void
    {
        $level = $this->parseLogLevel($config['level'] ?? 'debug');

        $handler = new StreamHandler('php://stderr', $level);
        $handler->setFormatter(new LineFormatter());
        
        $logger->addHandler($handler);
    }

    /**
     * Add stack handlers (multiple channels)
     */
    private function addStackHandlers(AsyncLogger $logger, array $config): void
    {
        $channels = $config['channels'] ?? [];
        
        foreach ($channels as $channelName) {
            if (isset($this->channels[$channelName])) {
                $channelConfig = $this->channels[$channelName];
                $this->configureLogger($logger, $channelConfig);
            }
        }
    }

    /**
     * Add ELK (Elasticsearch) handler
     */
    private function addElkHandler(AsyncLogger $logger, array $config): void
    {
        // For ELK integration, we'll use a custom handler that sends to Elasticsearch
        $handler = new ElkHandler($config);
        $logger->addHandler($handler);
    }

    /**
     * Add Kafka handler
     */
    private function addKafkaHandler(AsyncLogger $logger, array $config): void
    {
        // For Kafka integration, we'll use a custom handler
        $handler = new KafkaHandler($config);
        $logger->addHandler($handler);
    }

    /**
     * Parse log level string to Monolog level
     */
    private function parseLogLevel(string $level): int
    {
        $levels = [
            'debug' => \Monolog\Level::Debug->value,
            'info' => \Monolog\Level::Info->value,
            'notice' => \Monolog\Level::Notice->value,
            'warning' => \Monolog\Level::Warning->value,
            'error' => \Monolog\Level::Error->value,
            'critical' => \Monolog\Level::Critical->value,
            'alert' => \Monolog\Level::Alert->value,
            'emergency' => \Monolog\Level::Emergency->value,
        ];

        return $levels[strtolower($level)] ?? \Monolog\Level::Debug->value;
    }

    /**
     * Create a logger with custom configuration
     */
    public function createCustomLogger(string $name, array $config): AsyncLogger
    {
        $logger = new AsyncLogger($name);
        $this->configureLogger($logger, $config);
        return $logger;
    }

    /**
     * Flush all loggers
     */
    public function flushAll(): Future
    {
        return async(function() {
            $futures = [];
            
            foreach ($this->loggers as $logger) {
                if ($logger instanceof AsyncLogger) {
                    $futures[] = $logger->flush();
                }
            }
            
            // Wait for all flushes to complete
            if (!empty($futures)) {
                foreach ($futures as $future) {
                    $future->await();
                }
            }
        });
    }

    /**
     * Stop all loggers
     */
    public function stopAll(): Future
    {
        return async(function() {
            $futures = [];
            
            foreach ($this->loggers as $logger) {
                if ($logger instanceof AsyncLogger) {
                    $futures[] = $logger->stop();
                }
            }
            
            // Wait for all stops to complete
            if (!empty($futures)) {
                foreach ($futures as $future) {
                    $future->await();
                }
            }
        });
    }

    /**
     * Get statistics for all loggers
     */
    public function getStats(): array
    {
        $stats = [];
        
        foreach ($this->loggers as $name => $logger) {
            if ($logger instanceof AsyncLogger) {
                $stats[$name] = $logger->getStats();
            }
        }
        
        return $stats;
    }

    /**
     * Reload configuration and recreate loggers
     */
    public function reload(): void
    {
        // Stop existing loggers
        async(function() {
            $this->stopAll()->await();
        });
        
        // Clear loggers
        $this->loggers = [];
        
        // Reload configuration
        $this->loadConfiguration();
    }
}