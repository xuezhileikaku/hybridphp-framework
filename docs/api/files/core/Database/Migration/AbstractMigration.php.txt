<?php

declare(strict_types=1);

namespace HybridPHP\Core\Database\Migration;

use Amp\Future;

use HybridPHP\Core\Database\DatabaseInterface;

/**
 * Abstract base class for migrations
 */
abstract class AbstractMigration implements MigrationInterface
{
    protected DatabaseInterface $database;
    protected string $description = '';

    /**
     * Set database instance
     */
    public function setDatabase(DatabaseInterface $database): void
    {
        $this->database = $database;
    }

    /**
     * Get migration description
     */
    public function getDescription(): string
    {
        return $this->description ?: static::class;
    }

    /**
     * Execute SQL statement
     */
    protected function execute(string $sql, array $params = []): Future
    {
        return $this->database->execute($sql, $params);
    }

    /**
     * Execute SQL query
     */
    protected function query(string $sql, array $params = []): Future
    {
        return $this->database->query($sql, $params);
    }

    /**
     * Create table helper
     */
    protected function createTable(string $tableName, array $columns, array $options = []): Future
    {
        $sql = "CREATE TABLE `{$tableName}` (";
        
        $columnDefinitions = [];
        foreach ($columns as $name => $definition) {
            if (is_array($definition)) {
                $columnDefinitions[] = "`{$name}` " . $this->buildColumnDefinition($definition);
            } else {
                $columnDefinitions[] = "`{$name}` {$definition}";
            }
        }
        
        $sql .= implode(', ', $columnDefinitions);
        
        // Add table options
        if (!empty($options['primary_key'])) {
            $primaryKeys = is_array($options['primary_key']) ? $options['primary_key'] : [$options['primary_key']];
            $sql .= ', PRIMARY KEY (`' . implode('`, `', $primaryKeys) . '`)';
        }
        
        if (!empty($options['indexes'])) {
            foreach ($options['indexes'] as $indexName => $indexColumns) {
                $indexColumns = is_array($indexColumns) ? $indexColumns : [$indexColumns];
                $sql .= ", INDEX `{$indexName}` (`" . implode('`, `', $indexColumns) . '`)';
            }
        }
        
        if (!empty($options['unique'])) {
            foreach ($options['unique'] as $uniqueName => $uniqueColumns) {
                $uniqueColumns = is_array($uniqueColumns) ? $uniqueColumns : [$uniqueColumns];
                $sql .= ", UNIQUE KEY `{$uniqueName}` (`" . implode('`, `', $uniqueColumns) . '`)';
            }
        }
        
        $sql .= ')';
        
        // Add table options
        $engine = $options['engine'] ?? 'InnoDB';
        $charset = $options['charset'] ?? 'utf8mb4';
        $collation = $options['collation'] ?? 'utf8mb4_unicode_ci';
        
        $sql .= " ENGINE={$engine} DEFAULT CHARSET={$charset} COLLATE={$collation}";
        
        return $this->execute($sql);
    }

    /**
     * Drop table helper
     */
    protected function dropTable(string $tableName): Future
    {
        return $this->execute("DROP TABLE IF EXISTS `{$tableName}`");
    }

    /**
     * Add column helper
     */
    protected function addColumn(string $tableName, string $columnName, array $definition): Future
    {
        $sql = "ALTER TABLE `{$tableName}` ADD COLUMN `{$columnName}` " . $this->buildColumnDefinition($definition);
        return $this->execute($sql);
    }

    /**
     * Drop column helper
     */
    protected function dropColumn(string $tableName, string $columnName): Future
    {
        return $this->execute("ALTER TABLE `{$tableName}` DROP COLUMN `{$columnName}`");
    }

    /**
     * Add index helper
     */
    protected function addIndex(string $tableName, string $indexName, array $columns): Future
    {
        $sql = "ALTER TABLE `{$tableName}` ADD INDEX `{$indexName}` (`" . implode('`, `', $columns) . '`)';
        return $this->execute($sql);
    }

    /**
     * Drop index helper
     */
    protected function dropIndex(string $tableName, string $indexName): Future
    {
        return $this->execute("ALTER TABLE `{$tableName}` DROP INDEX `{$indexName}`");
    }

    /**
     * Build column definition from array
     */
    private function buildColumnDefinition(array $definition): string
    {
        $sql = $definition['type'];
        
        // Handle ENUM type
        if (strtoupper($definition['type']) === 'ENUM' && isset($definition['values'])) {
            $values = array_map(function($value) {
                return "'{$value}'";
            }, $definition['values']);
            $sql .= '(' . implode(', ', $values) . ')';
        } elseif (isset($definition['length'])) {
            $sql .= "({$definition['length']})";
        }
        
        if (isset($definition['unsigned']) && $definition['unsigned']) {
            $sql .= ' UNSIGNED';
        }
        
        if (isset($definition['nullable']) && !$definition['nullable']) {
            $sql .= ' NOT NULL';
        } elseif (!isset($definition['nullable']) || $definition['nullable']) {
            $sql .= ' NULL';
        }
        
        if (isset($definition['default'])) {
            if ($definition['default'] === null) {
                $sql .= ' DEFAULT NULL';
            } elseif (is_string($definition['default'])) {
                $sql .= " DEFAULT '{$definition['default']}'";
            } else {
                $sql .= " DEFAULT {$definition['default']}";
            }
        }
        
        if (isset($definition['auto_increment']) && $definition['auto_increment']) {
            $sql .= ' AUTO_INCREMENT';
        }
        
        if (isset($definition['comment'])) {
            $sql .= " COMMENT '{$definition['comment']}'";
        }
        
        return $sql;
    }
}