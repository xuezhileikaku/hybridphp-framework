<?php
namespace HybridPHP\Core;

use RuntimeException;
use InvalidArgumentException;

class ConfigManager
{
    protected array $config = [];
    protected array $loadedFiles = [];
    protected array $watchers = [];
    protected bool $cacheEnabled = true;
    protected string $cacheDir = '';
    protected string $configDir = '';

    public function __construct(string $configDir = '', string $cacheDir = '')
    {
        $this->configDir = $configDir ?: getcwd() . '/config';
        $this->cacheDir = $cacheDir ?: getcwd() . '/storage/cache';
        
        // Load environment variables
        $this->loadEnvironmentVariables();
    }

    /**
     * Load configuration from file or array
     */
    public function load($config, string $namespace = ''): void
    {
        if (is_string($config)) {
            $this->loadFromFile($config, $namespace);
        } elseif (is_array($config)) {
            $this->loadFromArray($config, $namespace);
        } else {
            throw new InvalidArgumentException('Config must be a file path or array');
        }
    }

    /**
     * Load configuration from file
     */
    protected function loadFromFile(string $filePath, string $namespace = ''): void
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("Configuration file not found: {$filePath}");
        }

        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $config = [];

        switch (strtolower($extension)) {
            case 'php':
                $config = require $filePath;
                break;
            case 'json':
                $config = json_decode(file_get_contents($filePath), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new RuntimeException("Invalid JSON in config file: {$filePath}");
                }
                break;
            case 'yaml':
            case 'yml':
                if (!function_exists('yaml_parse_file')) {
                    throw new RuntimeException('YAML extension not installed');
                }
                $config = yaml_parse_file($filePath);
                break;
            default:
                throw new RuntimeException("Unsupported config file format: {$extension}");
        }

        if (!is_array($config)) {
            throw new RuntimeException("Config file must return an array: {$filePath}");
        }

        $this->loadFromArray($config, $namespace);
        $this->loadedFiles[$filePath] = filemtime($filePath);
    }

    /**
     * Load configuration from array
     */
    protected function loadFromArray(array $config, string $namespace = ''): void
    {
        // Process environment variable substitution
        $config = $this->processEnvironmentVariables($config);

        if ($namespace) {
            $this->config[$namespace] = array_merge($this->config[$namespace] ?? [], $config);
        } else {
            $this->config = array_merge($this->config, $config);
        }
    }

    /**
     * Get configuration value using dot notation
     */
    public function get(string $key, $default = null)
    {
        return $this->getNestedValue($this->config, $key, $default);
    }

    /**
     * Set configuration value using dot notation
     */
    public function set(string $key, $value): void
    {
        $this->setNestedValue($this->config, $key, $value);
    }

    /**
     * Check if configuration key exists
     */
    public function has(string $key): bool
    {
        return $this->getNestedValue($this->config, $key, '__NOT_FOUND__') !== '__NOT_FOUND__';
    }

    /**
     * Get all configuration
     */
    public function all(): array
    {
        return $this->config;
    }

    /**
     * Reload all configuration files
     */
    public function reload(): void
    {
        $oldConfig = $this->config;
        $this->config = [];
        
        foreach ($this->loadedFiles as $filePath => $lastModified) {
            try {
                $this->loadFromFile($filePath);
            } catch (\Exception $e) {
                // Restore old config on error
                $this->config = $oldConfig;
                throw $e;
            }
        }
    }

    /**
     * Check if any config files have been modified
     */
    public function hasChanges(): bool
    {
        foreach ($this->loadedFiles as $filePath => $lastModified) {
            if (!file_exists($filePath) || filemtime($filePath) !== $lastModified) {
                return true;
            }
        }
        return false;
    }

    /**
     * Load environment variables from .env file
     */
    protected function loadEnvironmentVariables(): void
    {
        $envFile = getcwd() . '/.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) {
                    continue; // Skip comments
                }
                
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value, " \t\n\r\0\x0B\"'");
                    
                    if (!array_key_exists($key, $_ENV)) {
                        $_ENV[$key] = $value;
                        putenv("{$key}={$value}");
                    }
                }
            }
        }
    }

    /**
     * Process environment variable substitution in config
     */
    protected function processEnvironmentVariables($config)
    {
        if (is_array($config)) {
            foreach ($config as $key => $value) {
                $config[$key] = $this->processEnvironmentVariables($value);
            }
        } elseif (is_string($config)) {
            // Replace ${VAR_NAME} or ${VAR_NAME:default} patterns
            $config = preg_replace_callback('/\$\{([^}]+)\}/', function($matches) {
                $parts = explode(':', $matches[1], 2);
                $varName = $parts[0];
                $default = $parts[1] ?? '';
                
                return $_ENV[$varName] ?? getenv($varName) ?: $default;
            }, $config);
        }
        
        return $config;
    }

    /**
     * Get nested array value using dot notation
     */
    protected function getNestedValue(array $array, string $key, $default = null)
    {
        if (isset($array[$key])) {
            return $array[$key];
        }

        foreach (explode('.', $key) as $segment) {
            if (!is_array($array) || !array_key_exists($segment, $array)) {
                return $default;
            }
            $array = $array[$segment];
        }

        return $array;
    }

    /**
     * Set nested array value using dot notation
     */
    protected function setNestedValue(array &$array, string $key, $value): void
    {
        $keys = explode('.', $key);
        $current = &$array;

        foreach ($keys as $i => $key) {
            if ($i === count($keys) - 1) {
                $current[$key] = $value;
            } else {
                if (!isset($current[$key]) || !is_array($current[$key])) {
                    $current[$key] = [];
                }
                $current = &$current[$key];
            }
        }
    }

    /**
     * Enable or disable configuration caching
     */
    public function setCacheEnabled(bool $enabled): void
    {
        $this->cacheEnabled = $enabled;
    }

    /**
     * Validate configuration against schema
     */
    public function validate(array $schema): bool
    {
        // Basic validation implementation
        foreach ($schema as $key => $rules) {
            if (isset($rules['required']) && $rules['required'] && !$this->has($key)) {
                throw new RuntimeException("Required configuration key missing: {$key}");
            }
            
            if ($this->has($key) && isset($rules['type'])) {
                $value = $this->get($key);
                $expectedType = $rules['type'];
                
                if (gettype($value) !== $expectedType) {
                    throw new RuntimeException("Configuration key {$key} must be of type {$expectedType}");
                }
            }
        }
        
        return true;
    }
}
