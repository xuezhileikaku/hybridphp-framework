<?php

declare(strict_types=1);

namespace HybridPHP\Core\Database\Migration;

use Amp\Future;
use HybridPHP\Core\Database\DatabaseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use function Amp\async;

/**
 * Migration manager for handling database migrations
 */
class MigrationManager
{
    private DatabaseInterface $database;
    private array $config;
    private LoggerInterface $logger;
    private string $migrationsPath;
    private string $migrationsTable;

    public function __construct(
        DatabaseInterface $database,
        array $config = [],
        ?LoggerInterface $logger = null
    ) {
        $this->database = $database;
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
        $this->migrationsPath = $config['path'] ?? 'database/migrations';
        $this->migrationsTable = $config['table'] ?? 'migrations';
    }

    /**
     * Initialize migration system
     */
    public function initialize(): Future
    {
        return async(function () {
            $this->createMigrationsTable()->await();
            $this->logger->info('Migration system initialized');
        });
    }

    /**
     * Run pending migrations
     */
    public function migrate(): Future
    {
        return async(function () {
            $this->initialize()->await();
            
            $pendingMigrations = $this->getPendingMigrations()->await();
            $executedMigrations = [];
            
            if (empty($pendingMigrations)) {
                return $executedMigrations;
            }
            
            $batch = $this->getNextBatchNumber()->await();
            
            foreach ($pendingMigrations as $migrationFile) {
                try {
                    $this->database->transaction(function () use ($migrationFile, $batch) {
                        return async(function () use ($migrationFile, $batch) {
                            $migration = $this->loadMigration($migrationFile);
                            $migration->setDatabase($this->database);
                            
                            $this->logger->info("Running migration: {$migrationFile}");
                            $migration->up($this->database)->await();
                            
                            $this->recordMigration($migrationFile, $batch)->await();
                            $this->logger->info("Migration completed: {$migrationFile}");
                        });
                    })->await();
                    
                    $executedMigrations[] = $migrationFile;
                } catch (\Throwable $e) {
                    $this->logger->error("Migration failed: {$migrationFile}", [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    throw $e;
                }
            }
            
            return $executedMigrations;
        });
    }

    /**
     * Rollback migrations
     */
    public function rollback(int $steps = 1): Future
    {
        return async(function () use ($steps) {
            $this->initialize()->await();
            
            $migrationsToRollback = $this->getMigrationsToRollback($steps)->await();
            $rolledBackMigrations = [];
            
            if (empty($migrationsToRollback)) {
                return $rolledBackMigrations;
            }
            
            // Rollback in reverse order
            $migrationsToRollback = array_reverse($migrationsToRollback);
            
            foreach ($migrationsToRollback as $migrationRecord) {
                try {
                    $this->database->transaction(function () use ($migrationRecord) {
                        return async(function () use ($migrationRecord) {
                            $migration = $this->loadMigration($migrationRecord['migration']);
                            $migration->setDatabase($this->database);
                            
                            $this->logger->info("Rolling back migration: {$migrationRecord['migration']}");
                            $migration->down($this->database)->await();
                            
                            $this->removeMigrationRecord($migrationRecord['migration'])->await();
                            $this->logger->info("Migration rolled back: {$migrationRecord['migration']}");
                        });
                    })->await();
                    
                    $rolledBackMigrations[] = $migrationRecord['migration'];
                } catch (\Throwable $e) {
                    $this->logger->error("Rollback failed: {$migrationRecord['migration']}", [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    throw $e;
                }
            }
            
            return $rolledBackMigrations;
        });
    }

    /**
     * Rollback all migrations
     */
    public function rollbackAll(): Future
    {
        return async(function () {
            $this->initialize()->await();
            
            $allMigrations = $this->getExecutedMigrations()->await();
            
            if (empty($allMigrations)) {
                return [];
            }
            
            return $this->rollback(count($allMigrations))->await();
        });
    }

    /**
     * Get migration status
     */
    public function getStatus(): Future
    {
        return async(function () {
            $this->initialize()->await();
            
            $allMigrationFiles = $this->getAllMigrationFiles();
            $executedMigrations = $this->getExecutedMigrations()->await();
            
            $executedMap = [];
            foreach ($executedMigrations as $migration) {
                $executedMap[$migration['migration']] = $migration;
            }
            
            $status = [];
            foreach ($allMigrationFiles as $file) {
                $status[] = [
                    'filename' => $file,
                    'batch' => $executedMap[$file]['batch'] ?? null,
                    'executed_at' => $executedMap[$file]['executed_at'] ?? null
                ];
            }
            
            return $status;
        });
    }

    /**
     * Create a new migration file
     */
    public function createMigration(string $name): string
    {
        $timestamp = date('Y_m_d_His');
        $className = $this->studlyCase($name);
        $filename = "{$timestamp}_{$name}.php";
        $filepath = $this->migrationsPath . '/' . $filename;
        
        $template = $this->getMigrationTemplate($className, $name);
        
        if (!is_dir($this->migrationsPath)) {
            mkdir($this->migrationsPath, 0755, true);
        }
        
        file_put_contents($filepath, $template);
        
        return $filename;
    }

    /**
     * Create migrations table
     */
    private function createMigrationsTable(): Future
    {
        $sql = "CREATE TABLE IF NOT EXISTS `{$this->migrationsTable}` (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `migration` varchar(255) NOT NULL,
            `batch` int(11) NOT NULL,
            `executed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_migration` (`migration`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        return $this->database->execute($sql);
    }

    /**
     * Get pending migrations
     */
    private function getPendingMigrations(): Future
    {
        return async(function () {
            $allMigrations = $this->getAllMigrationFiles();
            $executedMigrations = $this->getExecutedMigrations()->await();
            
            $executedFiles = array_column($executedMigrations, 'migration');
            
            return array_diff($allMigrations, $executedFiles);
        });
    }

    /**
     * Get executed migrations
     */
    private function getExecutedMigrations(): Future
    {
        return async(function () {
            $result = $this->database->query(
                "SELECT migration, batch, executed_at FROM `{$this->migrationsTable}` ORDER BY batch ASC, migration ASC"
            )->await();
            
            $migrations = [];
            foreach ($result as $row) {
                $migrations[] = [
                    'migration' => $row['migration'],
                    'batch' => (int)$row['batch'],
                    'executed_at' => $row['executed_at']
                ];
            }
            
            return $migrations;
        });
    }

    /**
     * Get migrations to rollback
     */
    private function getMigrationsToRollback(int $steps): Future
    {
        return async(function () use ($steps) {
            $result = $this->database->query(
                "SELECT migration, batch FROM `{$this->migrationsTable}` ORDER BY batch DESC, migration DESC LIMIT ?",
                [$steps]
            )->await();
            
            $migrations = [];
            foreach ($result as $row) {
                $migrations[] = [
                    'migration' => $row['migration'],
                    'batch' => (int)$row['batch']
                ];
            }
            
            return $migrations;
        });
    }

    /**
     * Get next batch number
     */
    private function getNextBatchNumber(): Future
    {
        return async(function () {
            $result = $this->database->query(
                "SELECT MAX(batch) as max_batch FROM `{$this->migrationsTable}`"
            )->await();
            
            $row = $result[0] ?? null;
            
            return ($row['max_batch'] ?? 0) + 1;
        });
    }

    /**
     * Record migration execution
     */
    private function recordMigration(string $migration, int $batch): Future
    {
        return $this->database->execute(
            "INSERT INTO `{$this->migrationsTable}` (migration, batch) VALUES (?, ?)",
            [$migration, $batch]
        );
    }

    /**
     * Remove migration record
     */
    private function removeMigrationRecord(string $migration): Future
    {
        return $this->database->execute(
            "DELETE FROM `{$this->migrationsTable}` WHERE migration = ?",
            [$migration]
        );
    }

    /**
     * Get all migration files
     */
    private function getAllMigrationFiles(): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }
        
        $files = scandir($this->migrationsPath);
        $migrationFiles = [];
        
        foreach ($files as $file) {
            if (preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_.*\.php$/', $file)) {
                $migrationFiles[] = $file;
            }
        }
        
        sort($migrationFiles);
        return $migrationFiles;
    }

    /**
     * Load migration instance
     */
    private function loadMigration(string $filename): MigrationInterface
    {
        $filepath = $this->migrationsPath . '/' . $filename;
        
        if (!file_exists($filepath)) {
            throw new \RuntimeException("Migration file not found: {$filepath}");
        }
        
        require_once $filepath;
        
        // Extract class name from filename
        $className = $this->getClassNameFromFilename($filename);
        
        if (!class_exists($className)) {
            throw new \RuntimeException("Migration class not found: {$className}");
        }
        
        $migration = new $className();
        
        if (!$migration instanceof MigrationInterface) {
            throw new \RuntimeException("Migration must implement MigrationInterface: {$className}");
        }
        
        return $migration;
    }

    /**
     * Get class name from filename
     */
    private function getClassNameFromFilename(string $filename): string
    {
        // Remove timestamp and extension
        $name = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $filename);
        $name = str_replace('.php', '', $name);
        
        return $this->studlyCase($name);
    }

    /**
     * Convert string to StudlyCase
     */
    private function studlyCase(string $value): string
    {
        $value = str_replace(['-', '_'], ' ', $value);
        $value = ucwords($value);
        return str_replace(' ', '', $value);
    }

    /**
     * Drop all tables in the database
     */
    public function dropAllTables(): Future
    {
        return async(function () {
            // Disable foreign key checks
            $this->database->execute('SET FOREIGN_KEY_CHECKS = 0')->await();
            
            try {
                // Get all table names
                $result = $this->database->query('SHOW TABLES')->await();
                $tables = [];
                
                foreach ($result as $row) {
                    $tables[] = array_values($row)[0];
                }
                
                // Drop each table
                foreach ($tables as $table) {
                    $this->database->execute("DROP TABLE IF EXISTS `{$table}`")->await();
                    $this->logger->info("Dropped table: {$table}");
                }
                
                $this->logger->info('All tables dropped successfully');
            } finally {
                // Re-enable foreign key checks
                $this->database->execute('SET FOREIGN_KEY_CHECKS = 1')->await();
            }
        });
    }

    /**
     * Get migration template
     */
    private function getMigrationTemplate(string $className, string $name): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

use HybridPHP\Core\Database\Migration\AbstractMigration;
use HybridPHP\Core\Database\DatabaseInterface;
use Amp\Future;

/**
 * Migration: {$name}
 */
class {$className} extends AbstractMigration
{
    protected string \$description = '{$name}';

    /**
     * Run the migration
     */
    public function up(DatabaseInterface \$database): Promise
    {
        // TODO: Implement migration up logic
        // Example:
        // return \$this->createTable('example_table', [
        //     'id' => ['type' => 'INT', 'length' => 11, 'unsigned' => true, 'auto_increment' => true],
        //     'name' => ['type' => 'VARCHAR', 'length' => 255, 'nullable' => false],
        //     'created_at' => ['type' => 'TIMESTAMP', 'default' => 'CURRENT_TIMESTAMP'],
        // ], [
        //     'primary_key' => 'id'
        // ]);
        
        return \$this->execute('-- Add your migration SQL here');
    }

    /**
     * Reverse the migration
     */
    public function down(DatabaseInterface \$database): Promise
    {
        // TODO: Implement migration down logic
        // Example:
        // return \$this->dropTable('example_table');
        
        return \$this->execute('-- Add your rollback SQL here');
    }
}
PHP;
    }
}