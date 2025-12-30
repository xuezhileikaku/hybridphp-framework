<?php

declare(strict_types=1);

use HybridPHP\Core\Database\Seeder\AbstractSeeder;
use HybridPHP\Core\Database\DatabaseInterface;
use Amp\Future;
use function Amp\async;

/**
 * Post seeder
 */
class PostSeeder extends AbstractSeeder
{
    protected string $description = 'Post seeder';

    /**
     * Run the seeder
     */
    public function run(DatabaseInterface $database): Future
    {
        return async(function () {
            // Get user IDs first
            $result = $this->query('SELECT id FROM users LIMIT 10')->await();
            $userIds = [];
            
            while ($result->advance()->await()) {
                $row = $result->getCurrent();
                $userIds[] = $row['id'];
            }

            if (empty($userIds)) {
                return; // No users to create posts for
            }

            // Create sample posts
            $posts = [];
            for ($i = 1; $i <= 50; $i++) {
                $title = $this->fake()->text(50);
                $slug = strtolower(str_replace(' ', '-', preg_replace('/[^A-Za-z0-9 ]/', '', $title)));
                $status = ['draft', 'published', 'archived'][rand(0, 2)];
                
                $posts[] = [
                    'user_id' => $userIds[array_rand($userIds)],
                    'title' => $title,
                    'slug' => $slug . '-' . $i, // Ensure uniqueness
                    'content' => $this->fake()->text(500),
                    'excerpt' => $this->fake()->text(150),
                    'status' => $status,
                    'published_at' => $status === 'published' ? $this->fake()->date() : null,
                    'created_at' => $this->fake()->date(),
                    'updated_at' => $this->fake()->date(),
                ];
            }

            $this->insert('posts', $posts)->await();
        });
    }
}