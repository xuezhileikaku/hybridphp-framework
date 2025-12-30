<?php

declare(strict_types=1);

namespace HybridPHP\Core\Database\Seeder;

use Amp\Future;
use HybridPHP\Core\Database\DatabaseInterface;

/**
 * Interface for database seeders
 */
interface SeederInterface
{
    /**
     * Run the seeder
     */
    public function run(DatabaseInterface $database): Future;

    /**
     * Get seeder description
     */
    public function getDescription(): string;
}