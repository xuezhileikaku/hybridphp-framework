<?php

/**
 * GraphQL Usage Example
 * 
 * This example demonstrates how to use the HybridPHP GraphQL system
 * with async resolvers, DataLoader, and subscriptions.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use HybridPHP\Core\GraphQL\GraphQL;
use HybridPHP\Core\GraphQL\Schema;
use HybridPHP\Core\GraphQL\SchemaBuilder;
use HybridPHP\Core\GraphQL\Type\ObjectType;
use HybridPHP\Core\GraphQL\Type\ListType;
use HybridPHP\Core\GraphQL\Type\NonNullType;
use HybridPHP\Core\GraphQL\DataLoader\DataLoader;
use HybridPHP\Core\GraphQL\DataLoader\DataLoaderFactory;
use function Amp\async;
use function Amp\Future\await;

// Sample data
$users = [
    1 => ['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com'],
    2 => ['id' => 2, 'name' => 'Bob', 'email' => 'bob@example.com'],
    3 => ['id' => 3, 'name' => 'Charlie', 'email' => 'charlie@example.com'],
];

$posts = [
    ['id' => 1, 'title' => 'Hello World', 'authorId' => 1],
    ['id' => 2, 'title' => 'GraphQL is awesome', 'authorId' => 1],
    ['id' => 3, 'title' => 'Async PHP', 'authorId' => 2],
];

// Create DataLoader for users (solves N+1 problem)
$userLoader = new DataLoader(function (array $ids) use ($users) {
    return async(function () use ($ids, $users) {
        // Simulate async database query
        \Amp\delay(0.01);
        
        return array_map(
            fn($id) => $users[$id] ?? null,
            $ids
        );
    });
});

// Define User type
$userType = new ObjectType([
    'name' => 'User',
    'description' => 'A user in the system',
    'fields' => [
        'id' => [
            'type' => 'ID',
            'description' => 'The user ID',
        ],
        'name' => [
            'type' => 'String',
            'description' => 'The user name',
        ],
        'email' => [
            'type' => 'String',
            'description' => 'The user email',
        ],
    ],
]);

// Define Post type
$postType = new ObjectType([
    'name' => 'Post',
    'description' => 'A blog post',
    'fields' => fn() => [
        'id' => [
            'type' => 'ID',
        ],
        'title' => [
            'type' => 'String',
        ],
        'author' => [
            'type' => $userType,
            'resolve' => function ($post, $args, $context) use ($userLoader) {
                // Use DataLoader to batch load users
                return $userLoader->load($post['authorId']);
            },
        ],
    ],
]);

// Define Query type
$queryType = new ObjectType([
    'name' => 'Query',
    'fields' => [
        'hello' => [
            'type' => 'String',
            'args' => [
                'name' => [
                    'type' => 'String',
                    'defaultValue' => 'World',
                ],
            ],
            'resolve' => function ($root, $args) {
                return "Hello, {$args['name']}!";
            },
        ],
        'user' => [
            'type' => $userType,
            'args' => [
                'id' => [
                    'type' => new NonNullType('ID'),
                ],
            ],
            'resolve' => function ($root, $args) use ($userLoader) {
                return $userLoader->load((int) $args['id']);
            },
        ],
        'users' => [
            'type' => new ListType($userType),
            'resolve' => function () use ($users) {
                return async(function () use ($users) {
                    \Amp\delay(0.01); // Simulate async
                    return array_values($users);
                });
            },
        ],
        'posts' => [
            'type' => new ListType($postType),
            'resolve' => function () use ($posts) {
                return $posts;
            },
        ],
    ],
]);

// Define Mutation type
$mutationType = new ObjectType([
    'name' => 'Mutation',
    'fields' => [
        'createUser' => [
            'type' => $userType,
            'args' => [
                'name' => ['type' => new NonNullType('String')],
                'email' => ['type' => new NonNullType('String')],
            ],
            'resolve' => function ($root, $args) {
                return async(function () use ($args) {
                    // Simulate async database insert
                    \Amp\delay(0.01);
                    
                    return [
                        'id' => rand(100, 999),
                        'name' => $args['name'],
                        'email' => $args['email'],
                    ];
                });
            },
        ],
    ],
]);

// Define Subscription type
$subscriptionType = new ObjectType([
    'name' => 'Subscription',
    'fields' => [
        'userCreated' => [
            'type' => $userType,
            'resolve' => function ($payload) {
                return $payload;
            },
        ],
        'postCreated' => [
            'type' => $postType,
            'args' => [
                'authorId' => ['type' => 'ID'],
            ],
            'resolve' => function ($payload, $args) {
                // Filter by author if specified
                if (isset($args['authorId']) && $payload['authorId'] != $args['authorId']) {
                    return null;
                }
                return $payload;
            },
        ],
    ],
]);

// Build schema
$schema = new Schema([
    'query' => $queryType,
    'mutation' => $mutationType,
    'subscription' => $subscriptionType,
    'types' => [$userType, $postType],
]);

// Create GraphQL instance
$graphql = new GraphQL($schema);

// Register DataLoader
$graphql->registerDataLoader('users', $userLoader);

// Example queries
$queries = [
    // Simple query
    [
        'name' => 'Hello Query',
        'query' => '{ hello(name: "GraphQL") }',
    ],
    
    // Query with variables
    [
        'name' => 'User Query',
        'query' => 'query GetUser($id: ID!) { user(id: $id) { id name email } }',
        'variables' => ['id' => 1],
    ],
    
    // List query
    [
        'name' => 'Users List',
        'query' => '{ users { id name } }',
    ],
    
    // Nested query (demonstrates DataLoader)
    [
        'name' => 'Posts with Authors',
        'query' => '{ posts { id title author { id name } } }',
    ],
    
    // Mutation
    [
        'name' => 'Create User',
        'query' => 'mutation { createUser(name: "David", email: "david@example.com") { id name email } }',
    ],
    
    // Fragment usage
    [
        'name' => 'Fragment Query',
        'query' => '
            query {
                users {
                    ...UserFields
                }
            }
            fragment UserFields on User {
                id
                name
                email
            }
        ',
    ],
];

// Execute queries
echo "=== HybridPHP GraphQL Examples ===\n\n";

foreach ($queries as $example) {
    echo "--- {$example['name']} ---\n";
    echo "Query: " . trim($example['query']) . "\n";
    
    if (isset($example['variables'])) {
        echo "Variables: " . json_encode($example['variables']) . "\n";
    }
    
    $result = $graphql->execute(
        $example['query'],
        $example['variables'] ?? null
    )->await();
    
    echo "Result: " . json_encode($result, JSON_PRETTY_PRINT) . "\n\n";
}

// Subscription example
echo "--- Subscription Example ---\n";
$subscriptionManager = $graphql->getSubscriptionManager();

// Subscribe to userCreated
$subscriptionId = $subscriptionManager->subscribe(
    'subscription { userCreated { id name email } }',
    null,
    null,
    null,
    function ($result) {
        echo "Subscription received: " . json_encode($result) . "\n";
    }
)->await();

echo "Subscribed with ID: {$subscriptionId}\n";

// Publish an event
$subscriptionManager->publish('userCreated', [
    'id' => 999,
    'name' => 'New User',
    'email' => 'new@example.com',
]);

// Wait a bit for async processing
\Amp\delay(0.1);

// Unsubscribe
$subscriptionManager->unsubscribe($subscriptionId);
echo "Unsubscribed\n";

echo "\n=== Examples Complete ===\n";
