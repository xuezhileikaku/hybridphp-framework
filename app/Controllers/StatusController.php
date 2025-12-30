<?php
namespace App\Controllers;

use HybridPHP\Core\Http\Request;
use HybridPHP\Core\Http\Response;

class StatusController
{
    public function index(Request $request, array $params = []): Response
    {
        $status = [
            'application' => [
                'name' => 'HybridPHP Framework',
                'version' => '1.0.0-alpha',
                'environment' => $_ENV['APP_ENV'] ?? 'production',
                'debug' => (bool) ($_ENV['APP_DEBUG'] ?? false),
                'timezone' => date_default_timezone_get()
            ],
            'server' => [
                'software' => 'HybridPHP (Workerman + AMPHP)',
                'php_version' => PHP_VERSION,
                'os' => PHP_OS_FAMILY,
                'architecture' => php_uname('m'),
                'hostname' => gethostname()
            ],
            'runtime' => [
                'memory_usage' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true),
                'memory_limit' => ini_get('memory_limit'),
                'execution_time' => microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)),
                'included_files' => count(get_included_files())
            ],
            'features' => [
                'workerman' => class_exists('Workerman\Worker'),
                'amphp' => class_exists('Amp\Future'),
                'fast_route' => class_exists('FastRoute\Dispatcher'),
                'monolog' => class_exists('Monolog\Logger'),
                'psr_container' => interface_exists('Psr\Container\ContainerInterface')
            ],
            'extensions' => [
                'json' => extension_loaded('json'),
                'mbstring' => extension_loaded('mbstring'),
                'openssl' => extension_loaded('openssl'),
                'pcntl' => extension_loaded('pcntl'),
                'posix' => extension_loaded('posix'),
                'sockets' => extension_loaded('sockets')
            ],
            'timestamp' => date('c')
        ];

        return new Response(200, ['Content-Type' => 'application/json'], json_encode($status, JSON_PRETTY_PRINT));
    }
    
    public function apiStatus(Request $request, array $params = []): Response
    {
        return $this->index($request, $params);
    }
}