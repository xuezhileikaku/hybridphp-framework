<?php
namespace HybridPHP\Core\Logging;

use Amp\Future;
use function Amp\async;
use function Amp\delay;

/**
 * Log Archiver for managing log file rotation, compression, and cleanup
 */
class LogArchiver
{
    private array $config;
    private string $logDirectory;
    private int $maxFiles;
    private int $maxSize;
    private int $maxAge;
    private bool $compress;
    private string $compressionFormat;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->logDirectory = $config['directory'] ?? 'storage/logs';
        $this->maxFiles = $config['max_files'] ?? 30;
        $this->maxSize = $config['max_size'] ?? 10 * 1024 * 1024;
        $this->maxAge = $config['max_age_days'] ?? 30;
        $this->compress = $config['compress'] ?? true;
        $this->compressionFormat = $config['compression_format'] ?? 'gzip';
    }

    /**
     * Start automatic archiving process
     */
    public function startAutoArchiving(int $intervalSeconds = 3600): Future
    {
        return async(function() use ($intervalSeconds) {
            while (true) {
                try {
                    $this->performArchiving()->await();
                } catch (\Throwable $e) {
                    error_log("LogArchiver error: " . $e->getMessage());
                }
                
                delay($intervalSeconds);
            }
        });
    }

    /**
     * Perform archiving operations
     */
    public function performArchiving(): Future
    {
        return async(function() {
            $this->rotateLogFiles()->await();
            $this->compressOldLogs()->await();
            $this->cleanupOldLogs()->await();
        });
    }

    /**
     * Rotate log files based on size
     */
    private function rotateLogFiles(): Future
    {
        return async(function() {
            if (!is_dir($this->logDirectory)) {
                return;
            }

            $files = glob($this->logDirectory . '/*.log');
            
            foreach ($files as $file) {
                if (filesize($file) > $this->maxSize) {
                    $this->rotateFile($file)->await();
                }
            }
        });
    }

    /**
     * Rotate a single log file
     */
    private function rotateFile(string $file): Future
    {
        return async(function() use ($file) {
            $pathInfo = pathinfo($file);
            $baseName = $pathInfo['filename'];
            $extension = $pathInfo['extension'] ?? 'log';
            $directory = $pathInfo['dirname'];
            
            $rotationNumber = 1;
            while (file_exists("{$directory}/{$baseName}.{$rotationNumber}.{$extension}")) {
                $rotationNumber++;
            }
            
            $rotatedFile = "{$directory}/{$baseName}.{$rotationNumber}.{$extension}";
            
            if (rename($file, $rotatedFile)) {
                touch($file);
                chmod($file, 0644);
            }
        });
    }

    /**
     * Compress old log files
     */
    private function compressOldLogs(): Future
    {
        return async(function() {
            if (!$this->compress) {
                return;
            }

            $pattern = $this->logDirectory . '/*.*.log';
            $files = glob($pattern);
            
            foreach ($files as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) === 'gz') {
                    continue;
                }
                
                if (filemtime($file) < time() - 86400) {
                    $this->compressFile($file)->await();
                }
            }
        });
    }

    /**
     * Compress a single file
     */
    private function compressFile(string $file): Future
    {
        return async(function() use ($file) {
            try {
                switch ($this->compressionFormat) {
                    case 'gzip':
                        $this->gzipFile($file)->await();
                        break;
                    case 'zip':
                        $this->zipFile($file)->await();
                        break;
                    default:
                        throw new \InvalidArgumentException("Unsupported compression format: {$this->compressionFormat}");
                }
            } catch (\Throwable $e) {
                error_log("Failed to compress file {$file}: " . $e->getMessage());
            }
        });
    }

    /**
     * Compress file using gzip
     */
    private function gzipFile(string $file): Future
    {
        return async(function() use ($file) {
            $compressedFile = $file . '.gz';
            
            $input = fopen($file, 'rb');
            $output = gzopen($compressedFile, 'wb9');
            
            if ($input && $output) {
                while (!feof($input)) {
                    $chunk = fread($input, 8192);
                    gzwrite($output, $chunk);
                }
                
                fclose($input);
                gzclose($output);
                unlink($file);
            }
        });
    }

    /**
     * Compress file using zip
     */
    private function zipFile(string $file): Future
    {
        return async(function() use ($file) {
            $compressedFile = $file . '.zip';
            
            $zip = new \ZipArchive();
            if ($zip->open($compressedFile, \ZipArchive::CREATE) === TRUE) {
                $zip->addFile($file, basename($file));
                $zip->close();
                unlink($file);
            }
        });
    }

    /**
     * Clean up old log files
     */
    private function cleanupOldLogs(): Future
    {
        return async(function() {
            $cutoffTime = time() - ($this->maxAge * 86400);
            
            $files = glob($this->logDirectory . '/*');
            foreach ($files as $file) {
                if (is_file($file) && filemtime($file) < $cutoffTime) {
                    unlink($file);
                }
            }
            
            $this->cleanupByCount()->await();
        });
    }

    /**
     * Clean up files by count
     */
    private function cleanupByCount(): Future
    {
        return async(function() {
            $files = glob($this->logDirectory . '/*');
            
            usort($files, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            
            $filesToDelete = array_slice($files, $this->maxFiles);
            foreach ($filesToDelete as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        });
    }

    /**
     * Get archiver statistics
     */
    public function getStats(): array
    {
        $files = glob($this->logDirectory . '/*');
        $totalSize = 0;
        $fileCount = 0;
        $compressedCount = 0;
        
        foreach ($files as $file) {
            if (is_file($file)) {
                $totalSize += filesize($file);
                $fileCount++;
                
                if (in_array(pathinfo($file, PATHINFO_EXTENSION), ['gz', 'zip'])) {
                    $compressedCount++;
                }
            }
        }
        
        return [
            'directory' => $this->logDirectory,
            'total_files' => $fileCount,
            'compressed_files' => $compressedCount,
            'total_size' => $totalSize,
            'total_size_human' => $this->formatBytes($totalSize),
            'max_files' => $this->maxFiles,
            'max_size' => $this->maxSize,
            'max_age_days' => $this->maxAge,
            'compression_enabled' => $this->compress,
            'compression_format' => $this->compressionFormat,
        ];
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unitIndex = 0;
        
        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }
        
        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }

    public function cleanup(): Future
    {
        return $this->performArchiving();
    }
}
