<?php

declare(strict_types=1);

namespace HybridPHP\Core\Debug;

use HybridPHP\Core\ErrorHandler;
use Psr\Log\LoggerInterface;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Status;

/**
 * Enhanced error handler with detailed debugging information
 */
class DebugErrorHandler extends ErrorHandler
{
    private PerformanceProfiler $profiler;
    private array $debugInfo = [];
    private bool $collectStackTrace = true;
    private bool $showSourceCode = true;
    private int $sourceCodeLines = 10;

    public function __construct(
        ?LoggerInterface $logger = null,
        bool $debug = false,
        ?PerformanceProfiler $profiler = null
    ) {
        parent::__construct($logger, $debug);
        $this->profiler = $profiler ?? new PerformanceProfiler();
    }

    /**
     * Handle uncaught exceptions with detailed debugging
     */
    public function handleException(\Throwable $exception): void
    {
        $context = $this->createDetailedContext($exception);
        
        $this->logger->critical('Uncaught Exception: ' . $exception->getMessage(), $context);

        if ($this->debug || php_sapi_name() === 'cli') {
            $this->displayDetailedException($exception, $context);
        }

        // Prevent further execution
        if (php_sapi_name() !== 'cli') {
            http_response_code(500);
            if (!$this->debug) {
                echo "Internal Server Error";
            }
        }
    }

    /**
     * Handle HTTP exceptions and return detailed error response
     */
    public function handleHttpException(\Throwable $exception, ?Request $request = null): Response
    {
        $context = $this->createDetailedContext($exception, $request);
        
        $this->logger->error('HTTP Exception: ' . $exception->getMessage(), $context);

        if ($this->debug) {
            $html = $this->generateDetailedErrorPage($exception, $context);
            return new Response(Status::INTERNAL_SERVER_ERROR, [
                'content-type' => 'text/html; charset=utf-8',
            ], $html);
        }

        return new Response(Status::INTERNAL_SERVER_ERROR, [
            'content-type' => 'application/json',
        ], json_encode([
            'error' => 'Internal Server Error',
            'message' => $this->debug ? $exception->getMessage() : 'An error occurred',
            'timestamp' => date('c'),
        ]));
    }

    /**
     * Create detailed context for debugging
     */
    public function createDetailedContext(\Throwable $exception, ?Request $request = null): array
    {
        $context = parent::createContext($exception);
        
        // Add performance data
        $context['performance'] = $this->profiler->getSnapshot();
        
        // Add request information
        if ($request) {
            $context['request'] = [
                'method' => $request->getMethod(),
                'uri' => (string) $request->getUri(),
                'headers' => $request->getHeaders(),
                'query' => $request->getUri()->getQuery(),
                'user_agent' => $request->getHeader('user-agent'),
                'ip' => $this->getClientIp($request),
            ];
        }

        // Add environment information
        $context['environment'] = [
            'php_version' => PHP_VERSION,
            'sapi' => php_sapi_name(),
            'os' => PHP_OS,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'error_reporting' => error_reporting(),
        ];

        // Add stack trace with source code
        if ($this->collectStackTrace) {
            $context['detailed_trace'] = $this->getDetailedStackTrace($exception);
        }

        // Add debug information
        $context['debug_info'] = $this->debugInfo;

        return $context;
    }

    /**
     * Get detailed stack trace with source code
     */
    private function getDetailedStackTrace(\Throwable $exception): array
    {
        $trace = [];
        $frames = $exception->getTrace();
        
        // Add the exception location as first frame
        array_unshift($frames, [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'function' => '{main}',
            'class' => null,
            'type' => null,
            'args' => [],
        ]);

        foreach ($frames as $index => $frame) {
            $traceFrame = [
                'index' => $index,
                'file' => $frame['file'] ?? 'unknown',
                'line' => $frame['line'] ?? 0,
                'function' => $frame['function'] ?? 'unknown',
                'class' => $frame['class'] ?? null,
                'type' => $frame['type'] ?? null,
                'args' => $this->formatArguments($frame['args'] ?? []),
            ];

            // Add source code context
            if ($this->showSourceCode && isset($frame['file']) && is_file($frame['file'])) {
                $traceFrame['source'] = $this->getSourceCodeContext(
                    $frame['file'],
                    $frame['line'] ?? 1
                );
            }

            $trace[] = $traceFrame;
        }

        return $trace;
    }

    /**
     * Get source code context around a line
     */
    private function getSourceCodeContext(string $file, int $line): array
    {
        if (!is_readable($file)) {
            return [];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $start = max(0, $line - $this->sourceCodeLines - 1);
        $end = min(count($lines), $line + $this->sourceCodeLines);
        
        $context = [];
        for ($i = $start; $i < $end; $i++) {
            $context[] = [
                'line_number' => $i + 1,
                'code' => $lines[$i] ?? '',
                'is_error_line' => ($i + 1) === $line,
            ];
        }

        return $context;
    }

    /**
     * Format function arguments for display
     */
    private function formatArguments(array $args): array
    {
        $formatted = [];
        
        foreach ($args as $arg) {
            if (is_object($arg)) {
                $formatted[] = [
                    'type' => 'object',
                    'class' => get_class($arg),
                    'value' => method_exists($arg, '__toString') ? (string) $arg : '{object}',
                ];
            } elseif (is_array($arg)) {
                $formatted[] = [
                    'type' => 'array',
                    'count' => count($arg),
                    'value' => count($arg) <= 5 ? $arg : array_slice($arg, 0, 5, true) + ['...' => '...'],
                ];
            } elseif (is_resource($arg)) {
                $formatted[] = [
                    'type' => 'resource',
                    'resource_type' => get_resource_type($arg),
                    'value' => '{resource}',
                ];
            } elseif (is_string($arg)) {
                $formatted[] = [
                    'type' => 'string',
                    'length' => strlen($arg),
                    'value' => strlen($arg) > 100 ? substr($arg, 0, 100) . '...' : $arg,
                ];
            } else {
                $formatted[] = [
                    'type' => gettype($arg),
                    'value' => $arg,
                ];
            }
        }

        return $formatted;
    }

    /**
     * Display detailed exception in CLI or debug mode
     */
    protected function displayDetailedException(\Throwable $exception, array $context): void
    {
        if (php_sapi_name() === 'cli') {
            $this->displayCliException($exception, $context);
        } else {
            echo $this->generateDetailedErrorPage($exception, $context);
        }
    }

    /**
     * Display exception in CLI with detailed information
     */
    private function displayCliException(\Throwable $exception, array $context): void
    {
        echo "\n" . str_repeat('=', 100) . "\n";
        echo "UNCAUGHT EXCEPTION: " . get_class($exception) . "\n";
        echo str_repeat('=', 100) . "\n";
        echo "Message: " . $exception->getMessage() . "\n";
        echo "File: " . $exception->getFile() . ":" . $exception->getLine() . "\n";
        echo "Code: " . $exception->getCode() . "\n";
        
        // Performance info
        if (isset($context['performance'])) {
            echo "\nPerformance:\n";
            echo "  Memory: " . number_format($context['performance']['memory_usage'] / 1024 / 1024, 2) . " MB\n";
            echo "  Peak Memory: " . number_format($context['performance']['peak_memory'] / 1024 / 1024, 2) . " MB\n";
            echo "  Execution Time: " . number_format($context['performance']['execution_time'], 4) . "s\n";
        }

        echo "\nDetailed Stack Trace:\n";
        echo str_repeat('-', 100) . "\n";
        
        foreach ($context['detailed_trace'] as $frame) {
            echo "#{$frame['index']} ";
            
            if ($frame['class']) {
                echo $frame['class'] . $frame['type'] . $frame['function'] . "()";
            } else {
                echo $frame['function'] . "()";
            }
            
            echo " at {$frame['file']}:{$frame['line']}\n";
            
            // Show source code
            if (isset($frame['source']) && !empty($frame['source'])) {
                foreach ($frame['source'] as $sourceLine) {
                    $marker = $sourceLine['is_error_line'] ? '>>>' : '   ';
                    echo "    {$marker} {$sourceLine['line_number']}: {$sourceLine['code']}\n";
                }
                echo "\n";
            }
        }
        
        echo str_repeat('=', 100) . "\n\n";
    }

    /**
     * Generate detailed HTML error page
     */
    private function generateDetailedErrorPage(\Throwable $exception, array $context): string
    {
        $exceptionClass = get_class($exception);
        $message = htmlspecialchars($exception->getMessage());
        $file = htmlspecialchars($exception->getFile());
        $line = $exception->getLine();
        
        $performanceHtml = '';
        if (isset($context['performance'])) {
            $perf = $context['performance'];
            $performanceHtml = "
                <div class='performance-info'>
                    <h3>Performance Information</h3>
                    <div class='metric-grid'>
                        <div class='metric'>
                            <span class='label'>Memory Usage:</span>
                            <span class='value'>" . number_format($perf['memory_usage'] / 1024 / 1024, 2) . " MB</span>
                        </div>
                        <div class='metric'>
                            <span class='label'>Peak Memory:</span>
                            <span class='value'>" . number_format($perf['peak_memory'] / 1024 / 1024, 2) . " MB</span>
                        </div>
                        <div class='metric'>
                            <span class='label'>Execution Time:</span>
                            <span class='value'>" . number_format($perf['execution_time'], 4) . "s</span>
                        </div>
                        <div class='metric'>
                            <span class='label'>Active Coroutines:</span>
                            <span class='value'>" . ($perf['active_coroutines'] ?? 0) . "</span>
                        </div>
                    </div>
                </div>
            ";
        }

        $requestHtml = '';
        if (isset($context['request'])) {
            $req = $context['request'];
            $requestHtml = "
                <div class='request-info'>
                    <h3>Request Information</h3>
                    <div class='metric-grid'>
                        <div class='metric'>
                            <span class='label'>Method:</span>
                            <span class='value'>{$req['method']}</span>
                        </div>
                        <div class='metric'>
                            <span class='label'>URI:</span>
                            <span class='value'>" . htmlspecialchars($req['uri']) . "</span>
                        </div>
                        <div class='metric'>
                            <span class='label'>User Agent:</span>
                            <span class='value'>" . htmlspecialchars($req['user_agent'] ?? 'Unknown') . "</span>
                        </div>
                        <div class='metric'>
                            <span class='label'>IP Address:</span>
                            <span class='value'>" . htmlspecialchars($req['ip'] ?? 'Unknown') . "</span>
                        </div>
                    </div>
                </div>
            ";
        }

        $traceHtml = '';
        if (isset($context['detailed_trace'])) {
            $traceHtml = "<div class='stack-trace'><h3>Stack Trace</h3>";
            
            foreach ($context['detailed_trace'] as $frame) {
                $functionName = $frame['class'] ? 
                    $frame['class'] . $frame['type'] . $frame['function'] : 
                    $frame['function'];
                
                $traceHtml .= "
                    <div class='trace-frame'>
                        <div class='frame-header'>
                            <span class='frame-number'>#{$frame['index']}</span>
                            <span class='frame-function'>" . htmlspecialchars($functionName) . "()</span>
                            <span class='frame-location'>" . htmlspecialchars($frame['file']) . ":{$frame['line']}</span>
                        </div>
                ";
                
                if (isset($frame['source']) && !empty($frame['source'])) {
                    $traceHtml .= "<div class='source-code'>";
                    foreach ($frame['source'] as $sourceLine) {
                        $class = $sourceLine['is_error_line'] ? 'error-line' : '';
                        $traceHtml .= "
                            <div class='source-line {$class}'>
                                <span class='line-number'>{$sourceLine['line_number']}</span>
                                <span class='line-code'>" . htmlspecialchars($sourceLine['code']) . "</span>
                            </div>
                        ";
                    }
                    $traceHtml .= "</div>";
                }
                
                $traceHtml .= "</div>";
            }
            
            $traceHtml .= "</div>";
        }

        return "
<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Exception: {$exceptionClass}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace; background: #1e1e1e; color: #d4d4d4; line-height: 1.6; }
        .container { max-width: 1200px; margin: 0 auto; padding: 2rem; }
        .header { background: #e74c3c; color: white; padding: 2rem; margin-bottom: 2rem; border-radius: 8px; }
        .header h1 { font-size: 1.5rem; margin-bottom: 0.5rem; }
        .header .message { font-size: 1.1rem; opacity: 0.9; }
        .header .location { font-size: 0.9rem; opacity: 0.8; margin-top: 1rem; }
        .section { background: #2d2d30; margin-bottom: 2rem; border-radius: 8px; overflow: hidden; }
        .section h3 { background: #3c3c3c; padding: 1rem; margin: 0; color: #ffffff; }
        .section-content { padding: 1rem; }
        .metric-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; }
        .metric { display: flex; justify-content: space-between; padding: 0.5rem; background: #3c3c3c; border-radius: 4px; }
        .metric .label { color: #9cdcfe; }
        .metric .value { color: #ce9178; font-weight: bold; }
        .trace-frame { margin-bottom: 1.5rem; border: 1px solid #3c3c3c; border-radius: 4px; }
        .frame-header { background: #3c3c3c; padding: 1rem; display: flex; align-items: center; gap: 1rem; }
        .frame-number { background: #e74c3c; color: white; padding: 0.2rem 0.5rem; border-radius: 3px; font-size: 0.8rem; }
        .frame-function { color: #dcdcaa; font-weight: bold; }
        .frame-location { color: #9cdcfe; margin-left: auto; }
        .source-code { background: #1e1e1e; }
        .source-line { display: flex; padding: 0.3rem 1rem; border-bottom: 1px solid #2d2d30; }
        .source-line.error-line { background: #3c1e1e; border-left: 3px solid #e74c3c; }
        .line-number { color: #858585; width: 4rem; text-align: right; margin-right: 1rem; user-select: none; }
        .line-code { color: #d4d4d4; flex: 1; }
        .performance-info, .request-info { margin-bottom: 2rem; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>Uncaught Exception: {$exceptionClass}</h1>
            <div class='message'>{$message}</div>
            <div class='location'>in {$file} on line {$line}</div>
        </div>
        
        {$performanceHtml}
        {$requestHtml}
        {$traceHtml}
    </div>
</body>
</html>
        ";
    }

    /**
     * Get client IP address from request
     */
    private function getClientIp(Request $request): string
    {
        $headers = ['x-forwarded-for', 'x-real-ip', 'x-client-ip'];
        
        foreach ($headers as $header) {
            $ip = $request->getHeader($header);
            if ($ip) {
                return explode(',', $ip)[0];
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Add debug information
     */
    public function addDebugInfo(string $key, $value): void
    {
        $this->debugInfo[$key] = $value;
    }

    /**
     * Set source code display options
     */
    public function setSourceCodeOptions(bool $show = true, int $lines = 10): void
    {
        $this->showSourceCode = $show;
        $this->sourceCodeLines = $lines;
    }

    /**
     * Set stack trace collection
     */
    public function setStackTraceCollection(bool $collect = true): void
    {
        $this->collectStackTrace = $collect;
    }
}