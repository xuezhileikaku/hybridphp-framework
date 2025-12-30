<?php

declare(strict_types=1);

/**
 * HybridPHP Security Scanner
 * 
 * Performs comprehensive security scanning including:
 * - Dependency vulnerability checks
 * - Code security analysis
 * - Configuration security review
 * - File permission checks
 */

require_once __DIR__ . '/../vendor/autoload.php';

class SecurityScanner
{
    private string $projectRoot;
    private array $results = [];
    private array $config;
    
    public function __construct()
    {
        $this->projectRoot = dirname(__DIR__);
        $this->config = [
            'severity_threshold' => 'medium',
            'exclude_paths' => [
                'vendor/',
                'storage/',
                'tests/',
                '.git/'
            ],
            'sensitive_files' => [
                '.env',
                '.env.local',
                '.env.production',
                'config/database.php',
                'config/auth.php'
            ]
        ];
    }
    
    public function runAllScans(): array
    {
        echo "Starting HybridPHP Security Scan...\n";
        
        $this->scanDependencies();
        $this->scanCodeSecurity();
        $this->scanFilePermissions();
        $this->scanSensitiveFiles();
        $this->scanConfiguration();
        
        $this->generateReport();
        
        return $this->results;
    }
    
    private function scanDependencies(): void
    {
        echo "Scanning dependencies for vulnerabilities...\n";
        
        // Run composer audit
        $output = [];
        $returnCode = 0;
        exec('composer audit --format=json 2>/dev/null', $output, $returnCode);
        
        if ($returnCode === 0 && !empty($output)) {
            $auditData = json_decode(implode('', $output), true);
            
            if (isset($auditData['advisories'])) {
                foreach ($auditData['advisories'] as $package => $advisories) {
                    foreach ($advisories as $advisory) {
                        $this->results['dependencies'][] = [
                            'type' => 'vulnerability',
                            'severity' => $this->mapSeverity($advisory['severity'] ?? 'medium'),
                            'package' => $package,
                            'title' => $advisory['title'] ?? 'Unknown vulnerability',
                            'cve' => $advisory['cve'] ?? null,
                            'affected_versions' => $advisory['affectedVersions'] ?? 'unknown',
                            'patched_versions' => $advisory['patchedVersions'] ?? 'unknown'
                        ];
                    }
                }
            }
        }
        
        // Check for outdated packages
        exec('composer outdated --format=json 2>/dev/null', $output, $returnCode);
        if ($returnCode === 0 && !empty($output)) {
            $outdatedData = json_decode(implode('', $output), true);
            
            if (isset($outdatedData['installed'])) {
                foreach ($outdatedData['installed'] as $package) {
                    if (isset($package['latest-status']) && $package['latest-status'] === 'update-possible') {
                        $this->results['dependencies'][] = [
                            'type' => 'outdated',
                            'severity' => 'low',
                            'package' => $package['name'],
                            'current_version' => $package['version'],
                            'latest_version' => $package['latest']
                        ];
                    }
                }
            }
        }
    }
    
    private function scanCodeSecurity(): void
    {
        echo "Scanning code for security issues...\n";
        
        $patterns = [
            'sql_injection' => [
                'pattern' => '/\$[a-zA-Z_][a-zA-Z0-9_]*\s*\.\s*["\'].*?SELECT|INSERT|UPDATE|DELETE/i',
                'severity' => 'high',
                'description' => 'Potential SQL injection vulnerability'
            ],
            'xss' => [
                'pattern' => '/echo\s+\$[a-zA-Z_][a-zA-Z0-9_]*(?!\s*\|\s*htmlspecialchars)/i',
                'severity' => 'medium',
                'description' => 'Potential XSS vulnerability - unescaped output'
            ],
            'file_inclusion' => [
                'pattern' => '/(include|require)(_once)?\s*\(\s*\$[a-zA-Z_][a-zA-Z0-9_]*\s*\)/i',
                'severity' => 'high',
                'description' => 'Potential file inclusion vulnerability'
            ],
            'eval_usage' => [
                'pattern' => '/\beval\s*\(/i',
                'severity' => 'critical',
                'description' => 'Use of eval() function - potential code injection'
            ],
            'hardcoded_secrets' => [
                'pattern' => '/(password|secret|key|token)\s*=\s*["\'][^"\']{8,}["\']/i',
                'severity' => 'high',
                'description' => 'Potential hardcoded secret'
            ],
            'debug_functions' => [
                'pattern' => '/\b(var_dump|print_r|var_export)\s*\(/i',
                'severity' => 'low',
                'description' => 'Debug function found - should be removed in production'
            ]
        ];
        
        $this->scanDirectory($this->projectRoot, $patterns);
    }
    
    private function scanDirectory(string $dir, array $patterns): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            
            $relativePath = str_replace($this->projectRoot . DIRECTORY_SEPARATOR, '', $file->getPathname());
            
            // Skip excluded paths
            foreach ($this->config['exclude_paths'] as $excludePath) {
                if (strpos($relativePath, $excludePath) === 0) {
                    continue 2;
                }
            }
            
            $content = file_get_contents($file->getPathname());
            $lines = explode("\n", $content);
            
            foreach ($patterns as $patternName => $patternConfig) {
                if (preg_match_all($patternConfig['pattern'], $content, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[0] as $match) {
                        $lineNumber = substr_count(substr($content, 0, $match[1]), "\n") + 1;
                        
                        $this->results['code_security'][] = [
                            'type' => $patternName,
                            'severity' => $patternConfig['severity'],
                            'description' => $patternConfig['description'],
                            'file' => $relativePath,
                            'line' => $lineNumber,
                            'code' => trim($lines[$lineNumber - 1] ?? ''),
                            'match' => $match[0]
                        ];
                    }
                }
            }
        }
    }
    
    private function scanFilePermissions(): void
    {
        echo "Scanning file permissions...\n";
        
        $criticalFiles = [
            'bootstrap.php',
            'composer.json',
            'config/',
            '.env.example'
        ];
        
        foreach ($criticalFiles as $file) {
            $fullPath = $this->projectRoot . DIRECTORY_SEPARATOR . $file;
            
            if (file_exists($fullPath)) {
                $perms = fileperms($fullPath);
                $octal = substr(sprintf('%o', $perms), -4);
                
                // Check for world-writable files
                if ($perms & 0x0002) {
                    $this->results['file_permissions'][] = [
                        'type' => 'world_writable',
                        'severity' => 'high',
                        'file' => $file,
                        'permissions' => $octal,
                        'description' => 'File is world-writable'
                    ];
                }
                
                // Check for executable config files
                if (is_file($fullPath) && ($perms & 0x0040) && pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                    $this->results['file_permissions'][] = [
                        'type' => 'executable_config',
                        'severity' => 'medium',
                        'file' => $file,
                        'permissions' => $octal,
                        'description' => 'Configuration file is executable'
                    ];
                }
            }
        }
    }
    
    private function scanSensitiveFiles(): void
    {
        echo "Scanning for sensitive files...\n";
        
        foreach ($this->config['sensitive_files'] as $file) {
            $fullPath = $this->projectRoot . DIRECTORY_SEPARATOR . $file;
            
            if (file_exists($fullPath)) {
                $content = file_get_contents($fullPath);
                
                // Check for potential secrets in plain text
                $secretPatterns = [
                    'database_password' => '/DB_PASSWORD\s*=\s*["\']?[^"\'\s]{8,}["\']?/i',
                    'api_key' => '/(API_KEY|SECRET_KEY|ACCESS_TOKEN)\s*=\s*["\']?[^"\'\s]{16,}["\']?/i',
                    'jwt_secret' => '/JWT_SECRET\s*=\s*["\']?[^"\'\s]{32,}["\']?/i'
                ];
                
                foreach ($secretPatterns as $patternName => $pattern) {
                    if (preg_match($pattern, $content)) {
                        $this->results['sensitive_files'][] = [
                            'type' => $patternName,
                            'severity' => 'medium',
                            'file' => $file,
                            'description' => 'Potential sensitive data found in configuration file'
                        ];
                    }
                }
                
                // Check file permissions
                $perms = fileperms($fullPath);
                if ($perms & 0x0044) { // World or group readable
                    $this->results['sensitive_files'][] = [
                        'type' => 'readable_sensitive_file',
                        'severity' => 'high',
                        'file' => $file,
                        'permissions' => substr(sprintf('%o', $perms), -4),
                        'description' => 'Sensitive file is readable by others'
                    ];
                }
            }
        }
    }
    
    private function scanConfiguration(): void
    {
        echo "Scanning configuration security...\n";
        
        // Check debug mode in production
        $envFile = $this->projectRoot . '/.env.production';
        if (file_exists($envFile)) {
            $content = file_get_contents($envFile);
            
            if (preg_match('/APP_DEBUG\s*=\s*true/i', $content)) {
                $this->results['configuration'][] = [
                    'type' => 'debug_enabled_production',
                    'severity' => 'high',
                    'description' => 'Debug mode is enabled in production environment'
                ];
            }
        }
        
        // Check for insecure configurations
        $configFiles = glob($this->projectRoot . '/config/*.php');
        foreach ($configFiles as $configFile) {
            $content = file_get_contents($configFile);
            
            // Check for insecure session configuration
            if (strpos($configFile, 'session') !== false) {
                if (preg_match('/["\']secure["\']\s*=>\s*false/i', $content)) {
                    $this->results['configuration'][] = [
                        'type' => 'insecure_session',
                        'severity' => 'medium',
                        'file' => basename($configFile),
                        'description' => 'Session cookies are not marked as secure'
                    ];
                }
            }
        }
    }
    
    private function mapSeverity(string $severity): string
    {
        return match(strtolower($severity)) {
            'critical', 'high' => 'high',
            'medium', 'moderate' => 'medium',
            'low', 'info' => 'low',
            default => 'medium'
        };
    }
    
    private function generateReport(): void
    {
        echo "Generating security report...\n";
        
        $report = [
            'scan_date' => date('Y-m-d H:i:s'),
            'project_root' => $this->projectRoot,
            'summary' => $this->generateSummary(),
            'results' => $this->results
        ];
        
        // Save JSON report
        $reportPath = $this->projectRoot . '/storage/security_report.json';
        if (!is_dir(dirname($reportPath))) {
            mkdir(dirname($reportPath), 0755, true);
        }
        
        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT));
        
        // Generate HTML report
        $this->generateHtmlReport($report);
        
        // Display summary
        $this->displaySummary($report['summary']);
        
        echo "\nSecurity scan completed. Report saved to: {$reportPath}\n";
    }
    
    private function generateSummary(): array
    {
        $summary = [
            'total_issues' => 0,
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
            'categories' => []
        ];
        
        foreach ($this->results as $category => $issues) {
            $summary['categories'][$category] = count($issues);
            $summary['total_issues'] += count($issues);
            
            foreach ($issues as $issue) {
                $severity = $issue['severity'] ?? 'medium';
                if (isset($summary[$severity])) {
                    $summary[$severity]++;
                }
            }
        }
        
        return $summary;
    }
    
    private function generateHtmlReport(array $report): void
    {
        $html = '<!DOCTYPE html>
<html>
<head>
    <title>HybridPHP Security Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { background: #f4f4f4; padding: 20px; border-radius: 5px; }
        .summary { background: #e8f4f8; padding: 15px; margin: 20px 0; border-radius: 5px; }
        .critical { color: #d32f2f; font-weight: bold; }
        .high { color: #f57c00; font-weight: bold; }
        .medium { color: #fbc02d; }
        .low { color: #388e3c; }
        .issue { margin: 10px 0; padding: 10px; border-left: 4px solid #ccc; }
        .issue.critical { border-left-color: #d32f2f; }
        .issue.high { border-left-color: #f57c00; }
        .issue.medium { border-left-color: #fbc02d; }
        .issue.low { border-left-color: #388e3c; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <div class="header">
        <h1>HybridPHP Security Report</h1>
        <p>Generated: ' . $report['scan_date'] . '</p>
        <p>Project: ' . $report['project_root'] . '</p>
    </div>';
        
        // Add summary
        $summary = $report['summary'];
        $html .= '<div class="summary">
            <h2>Summary</h2>
            <p>Total Issues: ' . $summary['total_issues'] . '</p>
            <p>
                <span class="critical">Critical: ' . $summary['critical'] . '</span> | 
                <span class="high">High: ' . $summary['high'] . '</span> | 
                <span class="medium">Medium: ' . $summary['medium'] . '</span> | 
                <span class="low">Low: ' . $summary['low'] . '</span>
            </p>
        </div>';
        
        // Add detailed results
        foreach ($report['results'] as $category => $issues) {
            if (empty($issues)) continue;
            
            $html .= '<h2>' . ucwords(str_replace('_', ' ', $category)) . '</h2>';
            
            foreach ($issues as $issue) {
                $severity = $issue['severity'] ?? 'medium';
                $html .= '<div class="issue ' . $severity . '">';
                $html .= '<h4>' . ($issue['description'] ?? $issue['type']) . '</h4>';
                
                if (isset($issue['file'])) {
                    $html .= '<p><strong>File:</strong> ' . htmlspecialchars($issue['file']);
                    if (isset($issue['line'])) {
                        $html .= ':' . $issue['line'];
                    }
                    $html .= '</p>';
                }
                
                if (isset($issue['code'])) {
                    $html .= '<p><strong>Code:</strong> <code>' . htmlspecialchars($issue['code']) . '</code></p>';
                }
                
                $html .= '<p><strong>Severity:</strong> <span class="' . $severity . '">' . strtoupper($severity) . '</span></p>';
                $html .= '</div>';
            }
        }
        
        $html .= '</body></html>';
        
        file_put_contents($this->projectRoot . '/storage/security_report.html', $html);
    }
    
    private function displaySummary(array $summary): void
    {
        echo "\n=== Security Scan Summary ===\n";
        echo "Total Issues: {$summary['total_issues']}\n";
        echo "Critical: {$summary['critical']}\n";
        echo "High: {$summary['high']}\n";
        echo "Medium: {$summary['medium']}\n";
        echo "Low: {$summary['low']}\n";
        
        if ($summary['critical'] > 0 || $summary['high'] > 0) {
            echo "\n⚠️  Critical or high severity issues found! Please review immediately.\n";
            exit(1);
        } elseif ($summary['medium'] > 0) {
            echo "\n⚠️  Medium severity issues found. Please review.\n";
        } else {
            echo "\n✅ No critical security issues found.\n";
        }
    }
}

// Run security scan
try {
    $scanner = new SecurityScanner();
    $scanner->runAllScans();
} catch (Exception $e) {
    echo "Error running security scan: " . $e->getMessage() . "\n";
    exit(1);
}