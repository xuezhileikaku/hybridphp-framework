<?php

declare(strict_types=1);

namespace HybridPHP\Core\Monitoring;

use HybridPHP\Core\Application;
use HybridPHP\Core\Debug\DebugErrorHandler;
use HybridPHP\Core\Debug\PerformanceProfiler;
use HybridPHP\Core\Debug\CoroutineDebugger;
use HybridPHP\Core\Debug\QueryAnalyzer;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Psr\Log\LoggerInterface;

/**
 * Real-time monitoring dashboard
 */
class MonitoringDashboard
{
    private PerformanceMonitor $performanceMonitor;
    private AlertManager $alertManager;
    private Application $application;
    private ?LoggerInterface $logger;
    private array $config;
    private ?PerformanceProfiler $profiler;
    private ?CoroutineDebugger $coroutineDebugger;
    private ?QueryAnalyzer $queryAnalyzer;

    public function __construct(
        PerformanceMonitor $performanceMonitor,
        AlertManager $alertManager,
        Application $application,
        ?LoggerInterface $logger = null,
        array $config = [],
        ?PerformanceProfiler $profiler = null,
        ?CoroutineDebugger $coroutineDebugger = null,
        ?QueryAnalyzer $queryAnalyzer = null
    ) {
        $this->performanceMonitor = $performanceMonitor;
        $this->alertManager = $alertManager;
        $this->application = $application;
        $this->logger = $logger;
        $this->profiler = $profiler;
        $this->coroutineDebugger = $coroutineDebugger;
        $this->queryAnalyzer = $queryAnalyzer;
        $this->config = array_merge([
            'auth_enabled' => true,
            'auth_token' => null,
            'refresh_interval' => 5000, // milliseconds
        ], $config);
    }

    /**
     * Handle dashboard request
     */
    public function handleRequest(Request $request): Response
    {
        $path = $request->getUri()->getPath();
        
        // Authentication check
        if ($this->config['auth_enabled'] && !$this->isAuthenticated($request)) {
            return new Response(Status::UNAUTHORIZED, [], 'Unauthorized');
        }

        switch ($path) {
            case '/monitoring':
            case '/monitoring/':
                return $this->renderDashboard();
            
            case '/monitoring/api/metrics':
                return $this->getMetricsApi();
            
            case '/monitoring/api/alerts':
                return $this->getAlertsApi();
            
            case '/monitoring/api/performance':
                return $this->getPerformanceApi();
            
            case '/monitoring/prometheus':
                return $this->getPrometheusMetrics();
            
            case '/monitoring/health':
                return $this->getHealthStatus();
            
            case '/monitoring/api/profiler':
                return $this->getProfilerApi();
            
            case '/monitoring/api/coroutines':
                return $this->getCoroutinesApi();
            
            case '/monitoring/api/queries':
                return $this->getQueriesApi();
            
            case '/monitoring/debug':
                return $this->renderDebugDashboard();
            
            default:
                return new Response(Status::NOT_FOUND, [], 'Not Found');
        }
    }

    /**
     * Render main dashboard HTML
     */
    private function renderDashboard(): Response
    {
        $html = $this->getDashboardHtml();
        
        return new Response(Status::OK, [
            'content-type' => 'text/html; charset=utf-8',
            'cache-control' => 'no-cache, no-store, must-revalidate',
        ], $html);
    }

    /**
     * Get metrics API endpoint
     */
    private function getMetricsApi(): Response
    {
        try {
            $metrics = $this->performanceMonitor->getJsonMetrics();
            
            return new Response(Status::OK, [
                'content-type' => 'application/json',
                'cache-control' => 'no-cache',
            ], json_encode($metrics, JSON_PRETTY_PRINT));
        } catch (\Throwable $e) {
            return new Response(Status::INTERNAL_SERVER_ERROR, [
                'content-type' => 'application/json',
            ], json_encode(['error' => $e->getMessage()]));
        }
    }

    /**
     * Get alerts API endpoint
     */
    private function getAlertsApi(): Response
    {
        try {
            $alerts = [
                'active' => $this->alertManager->getActiveAlerts(),
                'statistics' => $this->alertManager->getStatistics(),
            ];
            
            return new Response(Status::OK, [
                'content-type' => 'application/json',
                'cache-control' => 'no-cache',
            ], json_encode($alerts, JSON_PRETTY_PRINT));
        } catch (\Throwable $e) {
            return new Response(Status::INTERNAL_SERVER_ERROR, [
                'content-type' => 'application/json',
            ], json_encode(['error' => $e->getMessage()]));
        }
    }

    /**
     * Get performance API endpoint
     */
    private function getPerformanceApi(): Response
    {
        try {
            $report = $this->performanceMonitor->getPerformanceReport();
            
            return new Response(Status::OK, [
                'content-type' => 'application/json',
                'cache-control' => 'no-cache',
            ], json_encode($report, JSON_PRETTY_PRINT));
        } catch (\Throwable $e) {
            return new Response(Status::INTERNAL_SERVER_ERROR, [
                'content-type' => 'application/json',
            ], json_encode(['error' => $e->getMessage()]));
        }
    }

    /**
     * Get Prometheus metrics endpoint
     */
    private function getPrometheusMetrics(): Response
    {
        try {
            $metrics = $this->performanceMonitor->getPrometheusMetrics();
            
            return new Response(Status::OK, [
                'content-type' => 'text/plain; version=0.0.4; charset=utf-8',
                'cache-control' => 'no-cache',
            ], $metrics);
        } catch (\Throwable $e) {
            return new Response(Status::INTERNAL_SERVER_ERROR, [
                'content-type' => 'text/plain',
            ], "# ERROR: {$e->getMessage()}");
        }
    }

    /**
     * Get health status endpoint
     */
    private function getHealthStatus(): Response
    {
        try {
            $status = [
                'status' => 'healthy',
                'timestamp' => date('c'),
                'uptime' => time() - ($_SERVER['REQUEST_TIME'] ?? time()),
                'version' => '1.0.0',
            ];
            
            return new Response(Status::OK, [
                'content-type' => 'application/json',
                'cache-control' => 'no-cache',
            ], json_encode($status));
        } catch (\Throwable $e) {
            return new Response(Status::INTERNAL_SERVER_ERROR, [
                'content-type' => 'application/json',
            ], json_encode([
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'timestamp' => date('c'),
            ]));
        }
    }

    /**
     * Check authentication
     */
    private function isAuthenticated(Request $request): bool
    {
        if (!$this->config['auth_enabled']) {
            return true;
        }

        $token = $this->config['auth_token'];
        if (!$token) {
            return true; // No token configured, allow access
        }

        // Check Authorization header
        $authHeader = $request->getHeader('authorization');
        if ($authHeader && strpos($authHeader, 'Bearer ') === 0) {
            $providedToken = substr($authHeader, 7);
            return hash_equals($token, $providedToken);
        }

        // Check query parameter
        $query = $request->getUri()->getQuery();
        parse_str($query, $params);
        if (isset($params['token'])) {
            return hash_equals($token, $params['token']);
        }

        return false;
    }

    /**
     * Get profiler API endpoint
     */
    private function getProfilerApi(): Response
    {
        try {
            if (!$this->profiler) {
                return new Response(Status::NOT_FOUND, [
                    'content-type' => 'application/json',
                ], json_encode(['error' => 'Profiler not available']));
            }

            $report = $this->profiler->getDetailedReport();
            
            return new Response(Status::OK, [
                'content-type' => 'application/json',
                'cache-control' => 'no-cache',
            ], json_encode($report, JSON_PRETTY_PRINT));
        } catch (\Throwable $e) {
            return new Response(Status::INTERNAL_SERVER_ERROR, [
                'content-type' => 'application/json',
            ], json_encode(['error' => $e->getMessage()]));
        }
    }

    /**
     * Get coroutines API endpoint
     */
    private function getCoroutinesApi(): Response
    {
        try {
            if (!$this->coroutineDebugger) {
                return new Response(Status::NOT_FOUND, [
                    'content-type' => 'application/json',
                ], json_encode(['error' => 'Coroutine debugger not available']));
            }

            $report = $this->coroutineDebugger->getDetailedReport();
            
            return new Response(Status::OK, [
                'content-type' => 'application/json',
                'cache-control' => 'no-cache',
            ], json_encode($report, JSON_PRETTY_PRINT));
        } catch (\Throwable $e) {
            return new Response(Status::INTERNAL_SERVER_ERROR, [
                'content-type' => 'application/json',
            ], json_encode(['error' => $e->getMessage()]));
        }
    }

    /**
     * Get queries API endpoint
     */
    private function getQueriesApi(): Response
    {
        try {
            if (!$this->queryAnalyzer) {
                return new Response(Status::NOT_FOUND, [
                    'content-type' => 'application/json',
                ], json_encode(['error' => 'Query analyzer not available']));
            }

            $analysis = $this->queryAnalyzer->getAnalysisReport();
            
            return new Response(Status::OK, [
                'content-type' => 'application/json',
                'cache-control' => 'no-cache',
            ], json_encode($analysis, JSON_PRETTY_PRINT));
        } catch (\Throwable $e) {
            return new Response(Status::INTERNAL_SERVER_ERROR, [
                'content-type' => 'application/json',
            ], json_encode(['error' => $e->getMessage()]));
        }
    }

    /**
     * Render debug dashboard
     */
    private function renderDebugDashboard(): Response
    {
        $html = $this->getDebugDashboardHtml();
        
        return new Response(Status::OK, [
            'content-type' => 'text/html; charset=utf-8',
            'cache-control' => 'no-cache, no-store, must-revalidate',
        ], $html);
    }

    /**
     * Generate debug dashboard HTML
     */
    private function getDebugDashboardHtml(): string
    {
        $refreshInterval = $this->config['refresh_interval'];
        
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HybridPHP Debug Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace; background: #1e1e1e; color: #d4d4d4; line-height: 1.6; }
        .header { background: #2d2d30; color: white; padding: 1rem; text-align: center; border-bottom: 2px solid #3c3c3c; }
        .nav { background: #252526; padding: 1rem; text-align: center; }
        .nav a { color: #9cdcfe; text-decoration: none; margin: 0 1rem; padding: 0.5rem 1rem; border-radius: 4px; }
        .nav a:hover, .nav a.active { background: #3c3c3c; }
        .container { max-width: 1400px; margin: 0 auto; padding: 2rem; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .card { background: #2d2d30; border-radius: 8px; padding: 1.5rem; border: 1px solid #3c3c3c; }
        .card h3 { color: #dcdcaa; margin-bottom: 1rem; border-bottom: 2px solid #569cd6; padding-bottom: 0.5rem; }
        .metric { display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid #3c3c3c; }
        .metric:last-child { border-bottom: none; }
        .metric-label { color: #9cdcfe; }
        .metric-value { font-weight: bold; color: #ce9178; }
        .alert { background: #3c1e1e; color: #f48771; padding: 0.5rem; border-radius: 4px; margin: 0.5rem 0; border-left: 3px solid #e74c3c; }
        .alert.warning { background: #3c2e1e; color: #ddb62b; border-left-color: #f39c12; }
        .alert.info { background: #1e2a3c; color: #6bb6ff; border-left-color: #3498db; }
        .status-indicator { display: inline-block; width: 12px; height: 12px; border-radius: 50%; margin-right: 8px; }
        .status-healthy { background: #4ec9b0; }
        .status-warning { background: #ddb62b; }
        .status-critical { background: #f48771; }
        .code-block { background: #1e1e1e; padding: 1rem; border-radius: 4px; font-family: 'Monaco', monospace; font-size: 0.9rem; overflow-x: auto; border: 1px solid #3c3c3c; }
        .query-item { background: #252526; padding: 1rem; margin: 0.5rem 0; border-radius: 4px; border-left: 3px solid #569cd6; }
        .query-slow { border-left-color: #f48771; }
        .query-sql { color: #ce9178; font-family: monospace; margin-bottom: 0.5rem; }
        .query-meta { color: #858585; font-size: 0.9rem; }
        .coroutine-item { background: #252526; padding: 1rem; margin: 0.5rem 0; border-radius: 4px; }
        .coroutine-running { border-left: 3px solid #4ec9b0; }
        .coroutine-completed { border-left: 3px solid #569cd6; }
        .coroutine-failed { border-left: 3px solid #f48771; }
        .loading { text-align: center; color: #858585; padding: 2rem; }
        .refresh-info { text-align: center; color: #858585; margin-top: 2rem; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 0.5rem; border-bottom: 1px solid #3c3c3c; }
        th { background: #252526; color: #dcdcaa; font-weight: 600; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .tabs { display: flex; background: #252526; border-radius: 4px; margin-bottom: 1rem; }
        .tab { padding: 0.75rem 1.5rem; cursor: pointer; color: #9cdcfe; border-radius: 4px; }
        .tab:hover { background: #3c3c3c; }
        .tab.active { background: #569cd6; color: white; }
    </style>
</head>
<body>
    <div class="header">
        <h1>HybridPHP Debug Dashboard</h1>
        <p>Advanced Debugging & Performance Analysis</p>
    </div>
    
    <div class="nav">
        <a href="/monitoring" class="nav-link">Main Dashboard</a>
        <a href="/monitoring/debug" class="nav-link active">Debug Dashboard</a>
        <a href="/monitoring/prometheus" class="nav-link">Prometheus Metrics</a>
    </div>
    
    <div class="container">
        <div class="tabs">
            <div class="tab active" onclick="showTab('overview')">Overview</div>
            <div class="tab" onclick="showTab('profiler')">Performance Profiler</div>
            <div class="tab" onclick="showTab('coroutines')">Coroutines</div>
            <div class="tab" onclick="showTab('queries')">Query Analysis</div>
        </div>
        
        <div id="overview" class="tab-content active">
            <div class="grid">
                <div class="card">
                    <h3>Debug Status</h3>
                    <div id="debug-status" class="loading">Loading...</div>
                </div>
                
                <div class="card">
                    <h3>Performance Summary</h3>
                    <div id="performance-summary" class="loading">Loading...</div>
                </div>
                
                <div class="card">
                    <h3>Active Issues</h3>
                    <div id="active-issues" class="loading">Loading...</div>
                </div>
            </div>
        </div>
        
        <div id="profiler" class="tab-content">
            <div class="grid">
                <div class="card">
                    <h3>Execution Timers</h3>
                    <div id="execution-timers" class="loading">Loading...</div>
                </div>
                
                <div class="card">
                    <h3>Memory Snapshots</h3>
                    <div id="memory-snapshots" class="loading">Loading...</div>
                </div>
            </div>
            
            <div class="card">
                <h3>System Information</h3>
                <div id="system-info" class="loading">Loading...</div>
            </div>
        </div>
        
        <div id="coroutines" class="tab-content">
            <div class="grid">
                <div class="card">
                    <h3>Coroutine Statistics</h3>
                    <div id="coroutine-stats" class="loading">Loading...</div>
                </div>
                
                <div class="card">
                    <h3>Active Coroutines</h3>
                    <div id="active-coroutines" class="loading">Loading...</div>
                </div>
            </div>
            
            <div class="card">
                <h3>Slow Coroutines</h3>
                <div id="slow-coroutines" class="loading">Loading...</div>
            </div>
        </div>
        
        <div id="queries" class="tab-content">
            <div class="grid">
                <div class="card">
                    <h3>Query Statistics</h3>
                    <div id="query-stats" class="loading">Loading...</div>
                </div>
                
                <div class="card">
                    <h3>Performance Issues</h3>
                    <div id="query-issues" class="loading">Loading...</div>
                </div>
            </div>
            
            <div class="card">
                <h3>Slow Queries</h3>
                <div id="slow-queries" class="loading">Loading...</div>
            </div>
            
            <div class="card">
                <h3>Duplicate Queries</h3>
                <div id="duplicate-queries" class="loading">Loading...</div>
            </div>
        </div>
        
        <div class="refresh-info">
            <p>Dashboard refreshes every {$refreshInterval}ms | Last updated: <span id="last-updated">-</span></p>
        </div>
    </div>

    <script>
        let refreshInterval = {$refreshInterval};
        let currentTab = 'overview';
        
        function showTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName).classList.add('active');
            
            // Add active class to selected tab
            event.target.classList.add('active');
            
            currentTab = tabName;
            updateDebugDashboard();
        }
        
        function updateDebugDashboard() {
            const promises = [
                fetch('/monitoring/api/performance').then(r => r.json()).catch(() => ({})),
            ];
            
            if (currentTab === 'profiler' || currentTab === 'overview') {
                promises.push(fetch('/monitoring/api/profiler').then(r => r.json()).catch(() => null));
            }
            
            if (currentTab === 'coroutines' || currentTab === 'overview') {
                promises.push(fetch('/monitoring/api/coroutines').then(r => r.json()).catch(() => null));
            }
            
            if (currentTab === 'queries' || currentTab === 'overview') {
                promises.push(fetch('/monitoring/api/queries').then(r => r.json()).catch(() => null));
            }
            
            Promise.all(promises).then(([performance, profiler, coroutines, queries]) => {
                if (currentTab === 'overview') {
                    updateOverviewTab(performance, profiler, coroutines, queries);
                } else if (currentTab === 'profiler' && profiler) {
                    updateProfilerTab(profiler);
                } else if (currentTab === 'coroutines' && coroutines) {
                    updateCoroutinesTab(coroutines);
                } else if (currentTab === 'queries' && queries) {
                    updateQueriesTab(queries);
                }
                
                document.getElementById('last-updated').textContent = new Date().toLocaleTimeString();
            }).catch(error => {
                console.error('Failed to update debug dashboard:', error);
            });
        }
        
        function updateOverviewTab(performance, profiler, coroutines, queries) {
            // Debug status
            const debugStatus = document.getElementById('debug-status');
            debugStatus.innerHTML = \`
                <div class="metric">
                    <span class="metric-label">Profiler</span>
                    <span class="metric-value">\${profiler ? 'Active' : 'Inactive'}</span>
                </div>
                <div class="metric">
                    <span class="metric-label">Coroutine Debugger</span>
                    <span class="metric-value">\${coroutines ? 'Active' : 'Inactive'}</span>
                </div>
                <div class="metric">
                    <span class="metric-label">Query Analyzer</span>
                    <span class="metric-value">\${queries ? 'Active' : 'Inactive'}</span>
                </div>
            \`;
            
            // Performance summary
            const perfSummary = document.getElementById('performance-summary');
            if (profiler && profiler.summary) {
                const summary = profiler.summary;
                perfSummary.innerHTML = \`
                    <div class="metric">
                        <span class="metric-label">Execution Time</span>
                        <span class="metric-value">\${summary.execution_time.toFixed(4)}s</span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Memory Used</span>
                        <span class="metric-value">\${(summary.memory_used / 1024 / 1024).toFixed(2)} MB</span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Active Timers</span>
                        <span class="metric-value">\${Object.keys(summary.running_timers || {}).length}</span>
                    </div>
                \`;
            } else {
                perfSummary.innerHTML = '<p class="loading">Profiler not available</p>';
            }
            
            // Active issues
            const issues = document.getElementById('active-issues');
            let issueHtml = '';
            
            if (queries && queries.performance_issues) {
                queries.performance_issues.forEach(issue => {
                    issueHtml += \`<div class="alert \${issue.severity}">\${issue.message}</div>\`;
                });
            }
            
            if (coroutines && coroutines.statistics) {
                const stats = coroutines.statistics;
                if (stats.failed_coroutines > 0) {
                    issueHtml += \`<div class="alert critical">\${stats.failed_coroutines} failed coroutines</div>\`;
                }
                if (stats.slow_coroutines > 0) {
                    issueHtml += \`<div class="alert warning">\${stats.slow_coroutines} slow coroutines</div>\`;
                }
            }
            
            issues.innerHTML = issueHtml || '<p style="color: #4ec9b0;">No active issues</p>';
        }
        
        function updateProfilerTab(profiler) {
            // Execution timers
            const timers = document.getElementById('execution-timers');
            if (profiler.timers) {
                let timerHtml = '';
                Object.entries(profiler.timers).forEach(([name, timer]) => {
                    if (!timer.running) {
                        timerHtml += \`
                            <div class="metric">
                                <span class="metric-label">\${name}</span>
                                <span class="metric-value">\${timer.duration.toFixed(4)}s</span>
                            </div>
                        \`;
                    }
                });
                timers.innerHTML = timerHtml || '<p>No completed timers</p>';
            }
            
            // Memory snapshots
            const memory = document.getElementById('memory-snapshots');
            if (profiler.memory_snapshots) {
                let memoryHtml = '';
                profiler.memory_snapshots.slice(-5).forEach(snapshot => {
                    memoryHtml += \`
                        <div class="metric">
                            <span class="metric-label">\${snapshot.label}</span>
                            <span class="metric-value">\${(snapshot.memory_usage / 1024 / 1024).toFixed(2)} MB</span>
                        </div>
                    \`;
                });
                memory.innerHTML = memoryHtml || '<p>No memory snapshots</p>';
            }
            
            // System info
            const sysInfo = document.getElementById('system-info');
            if (profiler.system_info) {
                const info = profiler.system_info;
                sysInfo.innerHTML = \`
                    <div class="code-block">
                        <div><strong>PHP Version:</strong> \${info.php_version}</div>
                        <div><strong>SAPI:</strong> \${info.php_sapi}</div>
                        <div><strong>OS:</strong> \${info.os}</div>
                        <div><strong>Memory Limit:</strong> \${info.memory_limit}</div>
                        <div><strong>Max Execution Time:</strong> \${info.max_execution_time}s</div>
                        <div><strong>OPcache:</strong> \${info.opcache_enabled ? 'Enabled' : 'Disabled'}</div>
                    </div>
                \`;
            }
        }
        
        function updateCoroutinesTab(coroutines) {
            // Statistics
            const stats = document.getElementById('coroutine-stats');
            if (coroutines.statistics) {
                const s = coroutines.statistics;
                stats.innerHTML = \`
                    <div class="metric">
                        <span class="metric-label">Total</span>
                        <span class="metric-value">\${s.total_coroutines}</span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Active</span>
                        <span class="metric-value">\${s.active_coroutines}</span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Completed</span>
                        <span class="metric-value">\${s.completed_coroutines}</span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Failed</span>
                        <span class="metric-value">\${s.failed_coroutines}</span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Success Rate</span>
                        <span class="metric-value">\${s.success_rate.toFixed(1)}%</span>
                    </div>
                \`;
            }
            
            // Active coroutines
            const active = document.getElementById('active-coroutines');
            if (coroutines.active_coroutines) {
                let activeHtml = '';
                Object.values(coroutines.active_coroutines).slice(0, 10).forEach(coroutine => {
                    activeHtml += \`
                        <div class="coroutine-item coroutine-running">
                            <div><strong>\${coroutine.name}</strong> (\${coroutine.id})</div>
                            <div class="query-meta">Status: \${coroutine.status} | Running: \${((Date.now() / 1000) - coroutine.created_at).toFixed(1)}s</div>
                        </div>
                    \`;
                });
                active.innerHTML = activeHtml || '<p>No active coroutines</p>';
            }
            
            // Slow coroutines
            const slow = document.getElementById('slow-coroutines');
            if (coroutines.slow_coroutines) {
                let slowHtml = '';
                coroutines.slow_coroutines.slice(0, 10).forEach(coroutine => {
                    slowHtml += \`
                        <div class="coroutine-item coroutine-completed">
                            <div><strong>\${coroutine.name}</strong> (\${coroutine.id})</div>
                            <div class="query-meta">Duration: \${coroutine.duration.toFixed(4)}s | Memory: \${((coroutine.memory_end - coroutine.memory_start) / 1024 / 1024).toFixed(2)} MB</div>
                        </div>
                    \`;
                });
                slow.innerHTML = slowHtml || '<p>No slow coroutines</p>';
            }
        }
        
        function updateQueriesTab(queries) {
            // Statistics
            const stats = document.getElementById('query-stats');
            if (queries.statistics) {
                const s = queries.statistics;
                stats.innerHTML = \`
                    <div class="metric">
                        <span class="metric-label">Total Queries</span>
                        <span class="metric-value">\${s.total_queries}</span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Slow Queries</span>
                        <span class="metric-value">\${s.slow_queries}</span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Duplicates</span>
                        <span class="metric-value">\${s.duplicate_queries}</span>
                    </div>
                    <div class="metric">
                        <span class="metric-label">Avg Duration</span>
                        <span class="metric-value">\${s.average_duration.toFixed(4)}s</span>
                    </div>
                \`;
            }
            
            // Performance issues
            const issues = document.getElementById('query-issues');
            if (queries.performance_issues) {
                let issueHtml = '';
                queries.performance_issues.forEach(issue => {
                    issueHtml += \`<div class="alert \${issue.severity}">\${issue.message}</div>\`;
                });
                issues.innerHTML = issueHtml || '<p style="color: #4ec9b0;">No performance issues</p>';
            }
            
            // Slow queries
            const slowQueries = document.getElementById('slow-queries');
            if (queries.slow_queries) {
                let slowHtml = '';
                queries.slow_queries.slice(0, 10).forEach(query => {
                    slowHtml += \`
                        <div class="query-item query-slow">
                            <div class="query-sql">\${query.sql.substring(0, 100)}...</div>
                            <div class="query-meta">Duration: \${query.duration.toFixed(4)}s | Type: \${query.type}</div>
                        </div>
                    \`;
                });
                slowQueries.innerHTML = slowHtml || '<p>No slow queries</p>';
            }
            
            // Duplicate queries
            const duplicates = document.getElementById('duplicate-queries');
            if (queries.duplicate_queries) {
                let dupHtml = '';
                Object.values(queries.duplicate_queries).slice(0, 5).forEach(dup => {
                    dupHtml += \`
                        <div class="query-item">
                            <div class="query-sql">\${dup.normalized_sql.substring(0, 100)}...</div>
                            <div class="query-meta">Count: \${dup.count} | Total Duration: \${dup.total_duration.toFixed(4)}s</div>
                        </div>
                    \`;
                });
                duplicates.innerHTML = dupHtml || '<p>No duplicate queries</p>';
            }
        }
        
        // Initial load and set up refresh
        updateDebugDashboard();
        setInterval(updateDebugDashboard, refreshInterval);
    </script>
</body>
</html>
HTML;
    }

    /**
     * Generate dashboard HTML
     */
    private function getDashboardHtml(): string
    {
        $refreshInterval = $this->config['refresh_interval'];
        
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HybridPHP Monitoring Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; }
        .header { background: #2c3e50; color: white; padding: 1rem; text-align: center; }
        .container { max-width: 1200px; margin: 0 auto; padding: 2rem; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .card { background: white; border-radius: 8px; padding: 1.5rem; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .card h3 { color: #2c3e50; margin-bottom: 1rem; border-bottom: 2px solid #3498db; padding-bottom: 0.5rem; }
        .metric { display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px solid #eee; }
        .metric:last-child { border-bottom: none; }
        .metric-value { font-weight: bold; color: #27ae60; }
        .alert { background: #e74c3c; color: white; padding: 0.5rem; border-radius: 4px; margin: 0.5rem 0; }
        .alert.warning { background: #f39c12; }
        .alert.info { background: #3498db; }
        .status-indicator { display: inline-block; width: 12px; height: 12px; border-radius: 50%; margin-right: 8px; }
        .status-healthy { background: #27ae60; }
        .status-warning { background: #f39c12; }
        .status-critical { background: #e74c3c; }
        .refresh-info { text-align: center; color: #7f8c8d; margin-top: 2rem; }
        .chart-container { height: 200px; background: #ecf0f1; border-radius: 4px; display: flex; align-items: center; justify-content: center; color: #7f8c8d; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 0.5rem; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; font-weight: 600; }
        .loading { text-align: center; color: #7f8c8d; padding: 2rem; }
    </style>
</head>
<body>
    <div class="header">
        <h1>HybridPHP Monitoring Dashboard</h1>
        <p>Real-time Performance & Health Monitoring</p>
    </div>
    
    <div class="container">
        <div class="grid">
            <div class="card">
                <h3>System Status</h3>
                <div id="system-status" class="loading">Loading...</div>
            </div>
            
            <div class="card">
                <h3>Performance Metrics</h3>
                <div id="performance-metrics" class="loading">Loading...</div>
            </div>
            
            <div class="card">
                <h3>Active Alerts</h3>
                <div id="active-alerts" class="loading">Loading...</div>
            </div>
        </div>
        
        <div class="grid">
            <div class="card">
                <h3>Request Statistics</h3>
                <div id="request-stats" class="loading">Loading...</div>
            </div>
            
            <div class="card">
                <h3>Coroutine Status</h3>
                <div id="coroutine-status" class="loading">Loading...</div>
            </div>
            
            <div class="card">
                <h3>Resource Usage</h3>
                <div id="resource-usage" class="loading">Loading...</div>
            </div>
        </div>
        
        <div class="card">
            <h3>Response Time Chart</h3>
            <div class="chart-container">
                Chart visualization would go here<br>
                <small>(Integration with Chart.js or similar library)</small>
            </div>
        </div>
        
        <div class="refresh-info">
            <p>Dashboard refreshes every {$refreshInterval}ms | Last updated: <span id="last-updated">-</span></p>
        </div>
    </div>

    <script>
        let refreshInterval = {$refreshInterval};
        
        function updateDashboard() {
            Promise.all([
                fetch('/monitoring/api/performance').then(r => r.json()),
                fetch('/monitoring/api/alerts').then(r => r.json()),
                fetch('/monitoring/api/metrics').then(r => r.json())
            ]).then(([performance, alerts, metrics]) => {
                updateSystemStatus(performance);
                updatePerformanceMetrics(performance);
                updateActiveAlerts(alerts);
                updateRequestStats(performance);
                updateCoroutineStatus(performance);
                updateResourceUsage(performance);
                
                document.getElementById('last-updated').textContent = new Date().toLocaleTimeString();
            }).catch(error => {
                console.error('Failed to update dashboard:', error);
            });
        }
        
        function updateSystemStatus(data) {
            const container = document.getElementById('system-status');
            const uptime = formatUptime(data.system.app_uptime_seconds || 0);
            const status = data.application.app_running ? 'healthy' : 'critical';
            
            container.innerHTML = `
                <div class="metric">
                    <span><span class="status-indicator status-\${status}"></span>Application Status</span>
                    <span class="metric-value">\${data.application.app_running ? 'Running' : 'Stopped'}</span>
                </div>
                <div class="metric">
                    <span>Uptime</span>
                    <span class="metric-value">\${uptime}</span>
                </div>
                <div class="metric">
                    <span>Active Coroutines</span>
                    <span class="metric-value">\${data.application.app_coroutines_active || 0}</span>
                </div>
            `;
        }
        
        function updatePerformanceMetrics(data) {
            const container = document.getElementById('performance-metrics');
            const memoryUsage = ((data.system.php_memory_usage_ratio || 0) * 100).toFixed(1);
            const cpuLoad = (data.system.system_load_1m || 0).toFixed(2);
            
            container.innerHTML = `
                <div class="metric">
                    <span>Memory Usage</span>
                    <span class="metric-value">\${memoryUsage}%</span>
                </div>
                <div class="metric">
                    <span>CPU Load (1m)</span>
                    <span class="metric-value">\${cpuLoad}</span>
                </div>
                <div class="metric">
                    <span>Disk Usage</span>
                    <span class="metric-value">\${((data.system.disk_usage_ratio || 0) * 100).toFixed(1)}%</span>
                </div>
            `;
        }
        
        function updateActiveAlerts(data) {
            const container = document.getElementById('active-alerts');
            const alerts = Object.values(data.active || {});
            
            if (alerts.length === 0) {
                container.innerHTML = '<p style="color: #27ae60;">No active alerts</p>';
                return;
            }
            
            let html = '';
            alerts.forEach(alert => {
                html += `<div class="alert \${alert.severity}">\${alert.name} (\${alert.count}x)</div>`;
            });
            
            container.innerHTML = html;
        }
        
        function updateRequestStats(data) {
            const container = document.getElementById('request-stats');
            const requests = data.requests || {};
            
            container.innerHTML = `
                <div class="metric">
                    <span>Total Requests</span>
                    <span class="metric-value">\${requests.total || 0}</span>
                </div>
                <div class="metric">
                    <span>Active Requests</span>
                    <span class="metric-value">\${data.application.app_requests_active || 0}</span>
                </div>
            `;
        }
        
        function updateCoroutineStatus(data) {
            const container = document.getElementById('coroutine-status');
            const coroutines = data.coroutines || {};
            
            container.innerHTML = `
                <div class="metric">
                    <span>Started</span>
                    <span class="metric-value">\${coroutines.started || 0}</span>
                </div>
                <div class="metric">
                    <span>Finished</span>
                    <span class="metric-value">\${coroutines.finished || 0}</span>
                </div>
                <div class="metric">
                    <span>Active</span>
                    <span class="metric-value">\${coroutines.active || 0}</span>
                </div>
            `;
        }
        
        function updateResourceUsage(data) {
            const container = document.getElementById('resource-usage');
            const memoryMB = Math.round((data.system.php_memory_usage_bytes || 0) / 1024 / 1024);
            const peakMB = Math.round((data.system.php_memory_peak_bytes || 0) / 1024 / 1024);
            
            container.innerHTML = `
                <div class="metric">
                    <span>Memory Current</span>
                    <span class="metric-value">\${memoryMB} MB</span>
                </div>
                <div class="metric">
                    <span>Memory Peak</span>
                    <span class="metric-value">\${peakMB} MB</span>
                </div>
            `;
        }
        
        function formatUptime(seconds) {
            const days = Math.floor(seconds / 86400);
            const hours = Math.floor((seconds % 86400) / 3600);
            const minutes = Math.floor((seconds % 3600) / 60);
            
            if (days > 0) return `\${days}d \${hours}h \${minutes}m`;
            if (hours > 0) return `\${hours}h \${minutes}m`;
            return `\${minutes}m`;
        }
        
        // Initial load and set up refresh
        updateDashboard();
        setInterval(updateDashboard, refreshInterval);
    </script>
</body>
</html>
HTML;
    }
}