<?php

declare(strict_types=1);

namespace App\Controllers;

use HybridPHP\Core\Http\Request;
use HybridPHP\Core\Http\Response;

class HealthController
{
    /**
     * Main health check endpoint
     */
    public function check(Request $request, array $params = []): Response
    {
        $health = [
            'status' => 'healthy',
            'timestamp' => date('c'),
            'uptime' => $this->getUptime(),
            'memory' => [
                'usage' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true),
                'limit' => ini_get('memory_limit')
            ],
            'system' => [
                'php_version' => PHP_VERSION,
                'os' => PHP_OS,
                'server_software' => 'HybridPHP/1.0'
            ],
            'checks' => [
                'filesystem' => $this->checkFilesystem()
            ]
        ];

        $overallStatus = $this->determineOverallStatus($health['checks']);
        $health['status'] = $overallStatus;

        $statusCode = $overallStatus === 'healthy' ? 200 : 503;

        return new Response($statusCode, ['Content-Type' => 'application/json'], json_encode($health, JSON_PRETTY_PRINT));
    }

    /**
     * API health check endpoint
     */
    public function apiCheck(Request $request, array $params = []): Response
    {
        return $this->check($request, $params);
    }

    /**
     * Liveness probe endpoint (simple check)
     */
    public function liveness(Request $request, array $params = []): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'status' => 'alive',
            'timestamp' => date('c')
        ]));
    }

    /**
     * Readiness probe endpoint
     */
    public function readiness(Request $request, array $params = []): Response
    {
        $ready = $this->checkFilesystem()['status'] === 'healthy';
        
        return new Response($ready ? 200 : 503, ['Content-Type' => 'application/json'], json_encode([
            'status' => $ready ? 'ready' : 'not_ready',
            'timestamp' => date('c')
        ]));
    }

    /**
     * Get application uptime
     */
    private function getUptime(): int
    {
        return time() - ($_SERVER['REQUEST_TIME'] ?? time());
    }

    /**
     * Check filesystem health
     */
    private function checkFilesystem(): array
    {
        $storagePath = __DIR__ . '/../../storage';
        $storageWritable = is_dir($storagePath) && is_writable($storagePath);
        
        return [
            'status' => $storageWritable ? 'healthy' : 'unhealthy',
            'storage_writable' => $storageWritable,
            'disk_space' => is_dir($storagePath) ? disk_free_space($storagePath) : 0
        ];
    }

    /**
     * Determine overall status from checks
     */
    private function determineOverallStatus(array $checks): string
    {
        foreach ($checks as $check) {
            if ($check['status'] !== 'healthy') {
                return 'unhealthy';
            }
        }
        return 'healthy';
    }
}
