<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Models\User;
use App\Models\Post;
use App\Entities\UserEntity;
use App\Mappers\UserMapper;
use HybridPHP\Core\Database\DatabaseInterface;
use HybridPHP\Core\Container;
use function Amp\async;

/**
 * ORM Usage Examples
 * 
 * This file demonstrates how to use the HybridPHP ORM system
 * with both ActiveRecord and DataMapper patterns.
 * 
 * Updated for AMPHP v3 - using ->await() instead of yield
 */

async(function () {
    echo "=== HybridPHP ORM Usage Examples ===\n\n";

    try {
        // Get database connection (assuming it's configured)
        $container = Container::getInstance();
        $db = $container->get(DatabaseInterface::class);

        echo "1. ActiveRecord Pattern Examples\n";
        echo "================================\n\n";

        // Create a new user using ActiveRecord
        echo "Creating a new user...\n";
        $user = new User([
            'username' => 'john_doe',
            'email' => 'john@example.com',
            'password' => 'secret123',
            'status' => 1
        ]);

        $saved = $user->save()->await();
        if ($saved) {
            echo "User created with ID: " . $user->id . "\n";
        } else {
            echo "Failed to create user. Errors: " . json_encode($user->getErrors()) . "\n";
        }

        // Find user by primary key
        echo "\nFinding user by ID...\n";
        $foundUser = User::findByPk($user->id)->await();
        if ($foundUser) {
            echo "Found user: " . $foundUser->username . " (" . $foundUser->email . ")\n";
        }

        // Find user by username
        echo "\nFinding user by username...\n";
        $userByUsername = User::findByUsername('john_doe')->await();
        if ($userByUsername) {
            echo "Found user by username: " . $userByUsername->email . "\n";
        }

        // Update user
        echo "\nUpdating user...\n";
        $foundUser->email = 'john.doe@example.com';
        $updated = $foundUser->save()->await();
        if ($updated) {
            echo "User updated successfully\n";
        }

        // Create a post for the user
        echo "\nCreating a post...\n";
        $post = new Post([
            'title' => 'My First Post',
            'content' => 'This is the content of my first post. It contains some interesting information.',
            'user_id' => $user->id,
            'status' => 1
        ]);

        $postSaved = $post->save()->await();
        if ($postSaved) {
            echo "Post created with ID: " . $post->id . "\n";
        }

        // Find posts by user
        echo "\nFinding posts by user...\n";
        $userPosts = Post::getByUser($user->id)->all()->await();
        echo "Found " . count($userPosts) . " posts for user\n";

        // Load user with posts (relations)
        echo "\nLoading user with posts...\n";
        $userWithPosts = User::find()->where(['id' => $user->id])->with(['posts'])->one()->await();
        if ($userWithPosts) {
            echo "User loaded with relations\n";
        }

        // Search posts
        echo "\nSearching posts...\n";
        $searchResults = Post::search('first')->all()->await();
        echo "Found " . count($searchResults) . " posts matching 'first'\n";

        // Count users
        echo "\nCounting users...\n";
        $userCount = User::count()->await();
        echo "Total users: $userCount\n";

        // Get active users
        echo "\nGetting active users...\n";
        $activeUsers = User::getActiveUsers()->all()->await();
        echo "Active users: " . count($activeUsers) . "\n";

        echo "\n2. DataMapper Pattern Examples\n";
        echo "==============================\n\n";

        // Create UserMapper instance
        $userMapper = new UserMapper($db);

        // Create a new user entity
        echo "Creating user entity...\n";
        $userEntity = new UserEntity([
            'username' => 'jane_doe',
            'email' => 'jane@example.com',
            'status' => 1
        ]);
        $userEntity->hashPassword('secret456');

        $entitySaved = $userMapper->save($userEntity)->await();
        if ($entitySaved) {
            echo "User entity saved with ID: " . $userEntity->getId() . "\n";
        }

        // Find entity by ID
        echo "\nFinding entity by ID...\n";
        $foundEntity = $userMapper->findById($userEntity->getId())->await();
        if ($foundEntity) {
            echo "Found entity: " . $foundEntity->getUsername() . " (" . $foundEntity->getEmail() . ")\n";
        }

        // Find by username
        echo "\nFinding entity by username...\n";
        $entityByUsername = $userMapper->findByUsername('jane_doe')->await();
        if ($entityByUsername) {
            echo "Found entity by username: " . $entityByUsername->getEmail() . "\n";
        }

        // Find active users
        echo "\nFinding active users...\n";
        $activeEntities = $userMapper->findActiveUsers()->await();
        echo "Found " . count($activeEntities) . " active users\n";

        // Update entity
        echo "\nUpdating entity...\n";
        $foundEntity->setEmail('jane.doe@example.com');
        $entityUpdated = $userMapper->save($foundEntity)->await();
        if ($entityUpdated) {
            echo "Entity updated successfully\n";
        }

        // Count entities
        echo "\nCounting entities...\n";
        $entityCount = $userMapper->count()->await();
        echo "Total user entities: $entityCount\n";

        // Transaction example
        echo "\n3. Transaction Examples\n";
        echo "=======================\n\n";

        echo "Using ActiveRecord transaction...\n";
        $result = $db->transaction(function ($db) {
            return async(function () {
                $user1 = new User(['username' => 'user1', 'email' => 'user1@example.com', 'password' => 'pass1']);
                $user2 = new User(['username' => 'user2', 'email' => 'user2@example.com', 'password' => 'pass2']);
                
                $saved1 = $user1->save()->await();
                $saved2 = $user2->save()->await();
                
                if (!$saved1 || !$saved2) {
                    throw new \Exception('Failed to save users');
                }
                
                return ['user1' => $user1->id, 'user2' => $user2->id];
            });
        })->await();
        
        if ($result) {
            echo "Transaction completed successfully. Created users: " . json_encode($result) . "\n";
        }

        echo "\nUsing DataMapper transaction...\n";
        $mapperResult = $userMapper->transaction(function ($mapper) use ($userMapper) {
            return async(function () use ($userMapper) {
                $entity1 = new UserEntity(['username' => 'entity1', 'email' => 'entity1@example.com']);
                $entity1->hashPassword('pass1');
                
                $entity2 = new UserEntity(['username' => 'entity2', 'email' => 'entity2@example.com']);
                $entity2->hashPassword('pass2');
                
                $saved1 = $userMapper->save($entity1)->await();
                $saved2 = $userMapper->save($entity2)->await();
                
                if (!$saved1 || !$saved2) {
                    throw new \Exception('Failed to save entities');
                }
                
                return ['entity1' => $entity1->getId(), 'entity2' => $entity2->getId()];
            });
        })->await();
        
        if ($mapperResult) {
            echo "DataMapper transaction completed successfully. Created entities: " . json_encode($mapperResult) . "\n";
        }

        echo "\n4. Validation Examples\n";
        echo "======================\n\n";

        // Test validation
        echo "Testing validation...\n";
        $invalidUser = new User([
            'username' => 'ab', // Too short
            'email' => 'invalid-email', // Invalid email
            'password' => '123' // Too short
        ]);

        $isValid = $invalidUser->validate()->await();
        if (!$isValid) {
            echo "Validation failed as expected. Errors:\n";
            foreach ($invalidUser->getErrors() as $attribute => $errors) {
                echo "  $attribute: " . implode(', ', $errors) . "\n";
            }
        }

        // Test unique validation
        echo "\nTesting unique validation...\n";
        $duplicateUser = new User([
            'username' => 'john_doe', // Already exists
            'email' => 'duplicate@example.com',
            'password' => 'password123'
        ]);

        $isDuplicateValid = $duplicateUser->validate()->await();
        if (!$isDuplicateValid) {
            echo "Unique validation failed as expected. Errors:\n";
            foreach ($duplicateUser->getErrors() as $attribute => $errors) {
                echo "  $attribute: " . implode(', ', $errors) . "\n";
            }
        }

        echo "\n=== ORM Examples Completed Successfully ===\n";

    } catch (\Throwable $e) {
        echo "Error: " . $e->getMessage() . "\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    }
})->await();
