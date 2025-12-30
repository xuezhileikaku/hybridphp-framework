<?php

declare(strict_types=1);

use HybridPHP\Core\Database\Seeder\AbstractSeeder;
use HybridPHP\Core\Database\DatabaseInterface;
use Amp\Future;
use function Amp\async;

/**
 * Main database seeder
 */
class DatabaseSeeder extends AbstractSeeder
{
    protected string $description = 'Main database seeder';

    /**
     * Run the seeder
     */
    public function run(DatabaseInterface $database): Future
    {
        return async(function () {
            // Call other seeders in order
            $this->call(UserSeeder::class)->await();
            $this->call(PostSeeder::class)->await();
        });
    }
}