<?php

namespace HybridPHP\Core\Cache;

use Amp\Future;
use Amp\File;
use function Amp\async;

/**
 * File-based cache implementation
 */
class FileCache extends AbstractCache
{
    private string $cachePath;

    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->cachePath = $config['path'] ?? 'storage/cache';
        
        // Ensure cache directory exists
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }
    }

    public function get(string $key, mixed $default = null): Future
    {
        return async(function () use ($key, $default) {
            $filePath = $this->getFilePath($key);
            
            if (!file_exists($filePath)) {
                return $default;
            }

            try {
                $content = file_get_contents($filePath);
                $data = unserialize($content);
                
                // Check expiry
                if ($data['expires'] > 0 && $data['expires'] < time()) {
                    @unlink($filePath);
                    return $default;
                }

                return $data['value'];
            } catch (\Throwable $e) {
                return $default;
            }
        });
    }

    public function set(string $key, mixed $value, ?int $ttl = null): Future
    {
        return async(function () use ($key, $value, $ttl) {
            $filePath = $this->getFilePath($key);
            $expires = $ttl ? time() + $this->getTtl($ttl) : 0;
            
            $data = [
                'value' => $value,
                'expires' => $expires,
                'created' => time(),
            ];

            try {
                file_put_contents($filePath, serialize($data), LOCK_EX);
                return true;
            } catch (\Throwable $e) {
                return false;
            }
        });
    }

    public function delete(string $key): Future
    {
        return async(function () use ($key) {
            $filePath = $this->getFilePath($key);
            
            if (file_exists($filePath)) {
                try {
                    @unlink($filePath);
                    return true;
                } catch (\Throwable $e) {
                    return false;
                }
            }

            return false;
        });
    }

    public function has(string $key): Future
    {
        return async(function () use ($key) {
            $filePath = $this->getFilePath($key);
            
            if (!file_exists($filePath)) {
                return false;
            }

            try {
                $content = file_get_contents($filePath);
                $data = unserialize($content);
                
                // Check expiry
                if ($data['expires'] > 0 && $data['expires'] < time()) {
                    @unlink($filePath);
                    return false;
                }

                return true;
            } catch (\Throwable $e) {
                return false;
            }
        });
    }

    public function clear(): Future
    {
        return async(function () {
            try {
                $files = glob($this->cachePath . '/*/*.cache');
                
                foreach ($files as $file) {
                    @unlink($file);
                }

                return true;
            } catch (\Throwable $e) {
                return false;
            }
        });
    }

    public function increment(string $key, int $value = 1): Future
    {
        return async(function () use ($key, $value) {
            $current = $this->get($key, 0)->await();
            $newValue = (int) $current + $value;
            $this->set($key, $newValue)->await();
            return $newValue;
        });
    }

    public function decrement(string $key, int $value = 1): Future
    {
        return async(function () use ($key, $value) {
            $current = $this->get($key, 0)->await();
            $newValue = (int) $current - $value;
            $this->set($key, $newValue)->await();
            return $newValue;
        });
    }

    public function getStats(): Future
    {
        return async(function () {
            try {
                $files = glob($this->cachePath . '/*/*.cache');
                $totalSize = 0;
                $fileCount = 0;
                $expiredCount = 0;

                foreach ($files as $file) {
                    $fileCount++;
                    $totalSize += filesize($file);

                    // Check if expired
                    try {
                        $content = file_get_contents($file);
                        $data = unserialize($content);
                        if ($data['expires'] > 0 && $data['expires'] < time()) {
                            $expiredCount++;
                        }
                    } catch (\Throwable $e) {
                        // Ignore corrupted files
                    }
                }

                return [
                    'total_files' => $fileCount,
                    'total_size' => $totalSize,
                    'expired_files' => $expiredCount,
                    'cache_path' => $this->cachePath,
                ];
            } catch (\Throwable $e) {
                return ['error' => $e->getMessage()];
            }
        });
    }

    protected function acquireLock(string $key, int $ttl): Future
    {
        return async(function () use ($key, $ttl) {
            $lockFile = $this->getFilePath($key . '.lock');
            
            if (file_exists($lockFile)) {
                // Check if lock is expired
                try {
                    $content = file_get_contents($lockFile);
                    $lockTime = (int) $content;
                    if ($lockTime + $ttl > time()) {
                        return false; // Lock still valid
                    }
                } catch (\Throwable $e) {
                    // Ignore and try to acquire lock
                }
            }

            try {
                file_put_contents($lockFile, (string) time(), LOCK_EX);
                return true;
            } catch (\Throwable $e) {
                return false;
            }
        });
    }

    protected function releaseLock(string $key): Future
    {
        return async(function () use ($key) {
            $lockFile = $this->getFilePath($key . '.lock');
            
            if (file_exists($lockFile)) {
                try {
                    @unlink($lockFile);
                } catch (\Throwable $e) {
                    // Ignore errors
                }
            }

            return true;
        });
    }

    /**
     * Get file path for cache key
     */
    private function getFilePath(string $key): string
    {
        $hash = md5($this->buildKey($key));
        $dir = $this->cachePath . '/' . substr($hash, 0, 2);
        
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir . '/' . $hash . '.cache';
    }
}