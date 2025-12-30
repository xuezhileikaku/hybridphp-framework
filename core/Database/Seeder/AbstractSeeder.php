<?php

declare(strict_types=1);

namespace HybridPHP\Core\Database\Seeder;

use Amp\Future;
use HybridPHP\Core\Database\DatabaseInterface;

/**
 * Abstract base class for seeders
 */
abstract class AbstractSeeder implements SeederInterface
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
     * Get seeder description
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
     * Insert data into table
     */
    protected function insert(string $table, array $data): Future
    {
        if (empty($data)) {
            throw new \InvalidArgumentException('Data cannot be empty');
        }

        // Handle single row or multiple rows
        $isMultipleRows = isset($data[0]) && is_array($data[0]);
        
        if (!$isMultipleRows) {
            $data = [$data];
        }

        $columns = array_keys($data[0]);
        $placeholders = '(' . str_repeat('?,', count($columns) - 1) . '?)';
        
        if ($isMultipleRows) {
            $allPlaceholders = str_repeat($placeholders . ',', count($data) - 1) . $placeholders;
        } else {
            $allPlaceholders = $placeholders;
        }

        $sql = "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES {$allPlaceholders}";
        
        $params = [];
        foreach ($data as $row) {
            foreach ($columns as $column) {
                $params[] = $row[$column] ?? null;
            }
        }

        return $this->execute($sql, $params);
    }

    /**
     * Update data in table
     */
    protected function update(string $table, array $data, array $where): Future
    {
        if (empty($data)) {
            throw new \InvalidArgumentException('Data cannot be empty');
        }

        if (empty($where)) {
            throw new \InvalidArgumentException('Where conditions cannot be empty');
        }

        $setParts = [];
        $params = [];

        foreach ($data as $column => $value) {
            $setParts[] = "`{$column}` = ?";
            $params[] = $value;
        }

        $whereParts = [];
        foreach ($where as $column => $value) {
            $whereParts[] = "`{$column}` = ?";
            $params[] = $value;
        }

        $sql = "UPDATE `{$table}` SET " . implode(', ', $setParts) . " WHERE " . implode(' AND ', $whereParts);

        return $this->execute($sql, $params);
    }

    /**
     * Delete data from table
     */
    protected function delete(string $table, array $where): Future
    {
        if (empty($where)) {
            throw new \InvalidArgumentException('Where conditions cannot be empty');
        }

        $whereParts = [];
        $params = [];

        foreach ($where as $column => $value) {
            $whereParts[] = "`{$column}` = ?";
            $params[] = $value;
        }

        $sql = "DELETE FROM `{$table}` WHERE " . implode(' AND ', $whereParts);

        return $this->execute($sql, $params);
    }

    /**
     * Truncate table
     */
    protected function truncate(string $table): Future
    {
        return $this->execute("TRUNCATE TABLE `{$table}`");
    }

    /**
     * Call another seeder
     */
    protected function call(string $seederClass): Future
    {
        if (!class_exists($seederClass)) {
            throw new \RuntimeException("Seeder class not found: {$seederClass}");
        }

        $seeder = new $seederClass();
        
        if (!$seeder instanceof SeederInterface) {
            throw new \RuntimeException("Seeder must implement SeederInterface: {$seederClass}");
        }

        $seeder->setDatabase($this->database);
        return $seeder->run($this->database);
    }

    /**
     * Generate fake data using simple generators
     */
    protected function fake(): FakeDataGenerator
    {
        return new FakeDataGenerator();
    }
}

/**
 * Simple fake data generator
 */
class FakeDataGenerator
{
    private array $firstNames = [
        'John', 'Jane', 'Michael', 'Sarah', 'David', 'Lisa', 'Robert', 'Emily',
        'James', 'Jessica', 'William', 'Ashley', 'Richard', 'Amanda', 'Thomas'
    ];

    private array $lastNames = [
        'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller',
        'Davis', 'Rodriguez', 'Martinez', 'Hernandez', 'Lopez', 'Gonzalez'
    ];

    private array $domains = [
        'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'example.com'
    ];

    public function name(): string
    {
        return $this->firstName() . ' ' . $this->lastName();
    }

    public function firstName(): string
    {
        return $this->firstNames[array_rand($this->firstNames)];
    }

    public function lastName(): string
    {
        return $this->lastNames[array_rand($this->lastNames)];
    }

    public function email(): string
    {
        $username = strtolower($this->firstName() . '.' . $this->lastName() . rand(1, 999));
        $domain = $this->domains[array_rand($this->domains)];
        return $username . '@' . $domain;
    }

    public function text(int $length = 100): string
    {
        $words = [
            'lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur', 'adipiscing',
            'elit', 'sed', 'do', 'eiusmod', 'tempor', 'incididunt', 'ut', 'labore',
            'et', 'dolore', 'magna', 'aliqua', 'enim', 'ad', 'minim', 'veniam'
        ];

        $text = '';
        while (strlen($text) < $length) {
            $text .= $words[array_rand($words)] . ' ';
        }

        return trim(substr($text, 0, $length));
    }

    public function number(int $min = 1, int $max = 100): int
    {
        return rand($min, $max);
    }

    public function boolean(): bool
    {
        return (bool)rand(0, 1);
    }

    public function date(string $format = 'Y-m-d H:i:s'): string
    {
        $timestamp = rand(strtotime('-1 year'), time());
        return date($format, $timestamp);
    }
}