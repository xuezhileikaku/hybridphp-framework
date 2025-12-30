<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use HybridPHP\Core\Database\DatabaseManager;
use HybridPHP\Core\Database\QueryBuilder;
use HybridPHP\Core\Database\DatabaseMonitor;
use HybridPHP\Core\FileLogger;
use function Amp\async;
use function Amp\delay;

// Example database configuration
$config = [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'host' => 'localhost',
            'port' => 3306,
            'username' => 'root',
            'password' => '',
            'database' => 'hybridphp_test',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'pool' => [
                'min' => 5,
                'max' => 50,
                'idle_timeout' => 60,
                'max_lifetime' => 3600,
            ],
        ],
        'mysql_read' => [
            'host' => 'localhost',
            'port' => 3306,
            'username' => 'root',
            'password' => '',
            'database' => 'hybridphp_test',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'read' => true,
            'pool' => [
                'min' => 3,
                'max' => 30,
                'idle_timeout' => 60,
                'max_lifetime' => 3600,
            ],
        ],
        'mysql_write' => [
            'host' => 'localhost',
            'port' => 3306,
            'username' => 'root',
            'password' => '',
            'database' => 'hybridphp_test',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'write' => true,
            'pool' => [
                'min' => 2,
                'max' => 20,
                'idle_timeout' => 60,
                'max_lifetime' => 3600,
            ],
        ],
    ],
];

async(function () use ($config) {
    $logger = new FileLogger('storage/logs/database.log');
    
    // Initialize database manager
    $dbManager = new DatabaseManager($config, $logger);
    
    echo "=== HybridPHP Database Usage Example ===\n\n";
    
    try {
        // 1. Basic connection test
        echo "1. Testing database connections...\n";
        $healthResults = $dbManager->healthCheckAll()->await();
        
        foreach ($healthResults as $connectionName => $result) {
            $status = $result['healthy'] ? 'HEALTHY' : 'UNHEALTHY';
            echo "   Connection '$connectionName': $status\n";
            if (!$result['healthy'] && $result['error']) {
                echo "   Error: {$result['error']}\n";
            }
        }
        echo "\n";
        
        // 2. Create test table (if not exists)
        echo "2. Creating test table...\n";
        $createTableSql = "
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) UNIQUE NOT NULL,
                status ENUM('active', 'inactive') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ";
        
        $dbManager->execute($createTableSql)->await();
        echo "   Test table created successfully\n\n";
        
        // 3. Insert test data using query builder
        echo "3. Inserting test data...\n";
        $database = $dbManager->writeConnection();
        $queryBuilder = new QueryBuilder($database);
        
        $testUsers = [
            ['name' => 'John Doe', 'email' => 'john@example.com', 'status' => 'active'],
            ['name' => 'Jane Smith', 'email' => 'jane@example.com', 'status' => 'active'],
            ['name' => 'Bob Johnson', 'email' => 'bob@example.com', 'status' => 'inactive'],
        ];
        
        foreach ($testUsers as $user) {
            try {
                $queryBuilder->table('users')->insert($user)->await();
                echo "   Inserted user: {$user['name']}\n";
            } catch (\Exception $e) {
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    echo "   User {$user['name']} already exists, skipping...\n";
                } else {
                    throw $e;
                }
            }
        }
        echo "\n";
        
        // 4. Query data using query builder
        echo "4. Querying data with Query Builder...\n";
        $readDatabase = $dbManager->readConnection();
        $readQueryBuilder = new QueryBuilder($readDatabase);
        
        // Get all active users
        $activeUsers = $readQueryBuilder
            ->table('users')
            ->select(['id', 'name', 'email', 'status'])
            ->where('status', '=', 'active')
            ->orderBy('created_at', 'DESC')
            ->get()->await();
        
        echo "   Active users found: " . count($activeUsers) . "\n";
        foreach ($activeUsers as $user) {
            echo "   - ID: {$user['id']}, Name: {$user['name']}, Email: {$user['email']}\n";
        }
        echo "\n";
        
        // 5. Transaction example
        echo "5. Testing transactions...\n";
        $dbManager->transaction(function ($db) {
            return async(function () use ($db) {
                echo "   Starting transaction...\n";
                
                // Update user status
                $queryBuilder = new QueryBuilder($db);
                $queryBuilder
                    ->table('users')
                    ->where('email', '=', 'john@example.com')
                    ->update(['status' => 'inactive'])->await();
                
                echo "   Updated user status in transaction\n";
                
                // You could throw an exception here to test rollback
                // throw new \Exception('Test rollback');
                
                return 'Transaction completed successfully';
            });
        })->await();
        echo "   Transaction completed\n\n";
        
        // 6. Connection pool statistics
        echo "6. Connection pool statistics...\n";
        $allStats = $dbManager->getAllStats();
        
        foreach ($allStats as $connectionName => $stats) {
            echo "   Connection '$connectionName':\n";
            echo "     Total connections: {$stats['total_connections']}\n";
            echo "     Active connections: {$stats['active_connections']}\n";
            echo "     Total queries: {$stats['total_queries']}\n";
            echo "     Failed queries: {$stats['failed_queries']}\n";
            echo "     Avg query time: " . round($stats['avg_query_time'] * 1000, 2) . "ms\n";
            echo "\n";
        }
        
        // 7. Start monitoring (for demonstration)
        echo "7. Starting database monitoring...\n";
        $monitor = new DatabaseMonitor($dbManager, $logger, [
            'interval' => 5, // Check every 5 seconds for demo
            'alert_thresholds' => [
                'max_active_connections' => 80,
                'max_failed_queries' => 10,
                'max_avg_query_time' => 1000,
            ],
        ]);
        
        $monitor->start();
        echo "   Database monitoring started\n";
        
        // Wait a bit to collect some metrics
        delay(6); // 6 seconds
        
        $metricsSummary = $monitor->getMetricsSummary();
        echo "   Metrics summary:\n";
        foreach ($metricsSummary as $connectionName => $metrics) {
            echo "     Connection '$connectionName':\n";
            echo "       Status: {$metrics['status']}\n";
            echo "       Active connections: {$metrics['active_connections']}\n";
            echo "       Total queries: {$metrics['total_queries']}\n";
            echo "       Avg query time: {$metrics['avg_query_time_ms']}ms\n";
            echo "\n";
        }
        
        $monitor->stop();
        echo "   Database monitoring stopped\n\n";
        
        // 8. Prometheus metrics export
        echo "8. Prometheus metrics export:\n";
        $prometheusMetrics = $monitor->exportPrometheusMetrics();
        echo $prometheusMetrics;
        
        echo "=== Database usage example completed successfully ===\n";
        
    } catch (\Throwable $e) {
        echo "Error: " . $e->getMessage() . "\n";
        echo "Trace: " . $e->getTraceAsString() . "\n";
    } finally {
        // Clean up connections
        $dbManager->closeAll()->await();
        echo "All database connections closed.\n";
    }
});