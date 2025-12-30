<?php

declare(strict_types=1);

namespace HybridPHP\Core\Database\Migration;

use Amp\Future;
use HybridPHP\Core\Database\DatabaseInterface;

/**
 * Interface for database migrations
 */
interface MigrationInterface
{
    /**
     * Run the migration
     */
    public function up(DatabaseInterface $database): Future;

    /**
     * Reverse the migration
     */
    public function down(DatabaseInterface $database): Future;

    /**
     * Get migration description
     */
    public function getDescription(): string;
}