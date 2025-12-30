<?php

declare(strict_types=1);

use HybridPHP\Core\Database\Seeder\AbstractSeeder;
use HybridPHP\Core\Database\DatabaseInterface;
use Amp\Future;
use function Amp\async;

/**
 * User seeder
 */
class UserSeeder extends AbstractSeeder
{
    protected string $description = 'User seeder';

    /**
     * Run the seeder
     */
    public function run(DatabaseInterface $database): Future
    {
        return async(function () {
            // Create admin user
            $this->insert('users', [
                'name' => 'Administrator',
                'email' => 'admin@hybridphp.com',
                'password' => password_hash('admin123', PASSWORD_DEFAULT),
                'email_verified_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ])->await();

            // Create sample users
            $users = [];
            for ($i = 1; $i <= 10; $i++) {
                $users[] = [
                    'name' => $this->fake()->name(),
                    'email' => $this->fake()->email(),
                    'password' => password_hash('password', PASSWORD_DEFAULT),
                    'email_verified_at' => rand(0, 1) ? $this->fake()->date() : null,
                    'created_at' => $this->fake()->date(),
                    'updated_at' => $this->fake()->date(),
                ];
            }

            $this->insert('users', $users)->await();
        });
    }
}