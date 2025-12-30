<?php

declare(strict_types=1);

namespace HybridPHP\Core\GraphQL;

use HybridPHP\Core\Container;
use HybridPHP\Core\GraphQL\Http\GraphQLHandler;
use HybridPHP\Core\GraphQL\Http\GraphQLWebSocketHandler;
use HybridPHP\Core\GraphQL\Subscription\SubscriptionManager;
use HybridPHP\Core\GraphQL\Subscription\PubSub;
use HybridPHP\Core\GraphQL\Subscription\InMemoryPubSub;

/**
 * GraphQL Service Provider
 */
class GraphQLServiceProvider
{
    protected Container $container;
    protected array $config;

    public function __construct(Container $container, array $config = [])
    {
        $this->container = $container;
        $this->config = array_merge([
            'enabled' => true,
            'endpoint' => '/graphql',
            'subscriptions' => [
                'enabled' => true,
                'endpoint' => '/graphql/subscriptions',
            ],
            'introspection' => true,
            'debug' => false,
            'maxBatchSize' => 10,
            'maxQueryDepth' => 15,
            'maxQueryComplexity' => 100,
        ], $config);
    }

    /**
     * Register GraphQL services
     */
    public function register(): void
    {
        if (!$this->config['enabled']) {
            return;
        }

        // Register PubSub
        $this->container->singleton(PubSub::class, function () {
            return new InMemoryPubSub();
        });

        // Register Schema (to be configured by application)
        $this->container->singleton(Schema::class, function () {
            // Default empty schema - should be overridden by application
            return new Schema([
                'query' => new Type\ObjectType([
                    'name' => 'Query',
                    'fields' => [
                        'hello' => [
                            'type' => 'String',
                            'resolve' => fn() => 'Hello, GraphQL!',
                        ],
                    ],
                ]),
            ]);
        });

        // Register GraphQL instance
        $this->container->singleton(GraphQL::class, function () {
            $schema = $this->container->get(Schema::class);
            return new GraphQL($schema);
        });

        // Register SubscriptionManager
        $this->container->singleton(SubscriptionManager::class, function () {
            $schema = $this->container->get(Schema::class);
            $pubSub = $this->container->get(PubSub::class);
            return new SubscriptionManager($schema, $pubSub);
        });

        // Register HTTP Handler
        $this->container->singleton(GraphQLHandler::class, function () {
            $graphql = $this->container->get(GraphQL::class);
            return new GraphQLHandler($graphql, [
                'debug' => $this->config['debug'],
                'introspection' => $this->config['introspection'],
                'maxBatchSize' => $this->config['maxBatchSize'],
                'maxQueryDepth' => $this->config['maxQueryDepth'],
                'maxQueryComplexity' => $this->config['maxQueryComplexity'],
            ]);
        });

        // Register WebSocket Handler
        if ($this->config['subscriptions']['enabled']) {
            $this->container->singleton(GraphQLWebSocketHandler::class, function () {
                $graphql = $this->container->get(GraphQL::class);
                return new GraphQLWebSocketHandler($graphql);
            });
        }
    }

    /**
     * Boot GraphQL services
     */
    public function boot(): void
    {
        // Additional boot logic if needed
    }

    /**
     * Get configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }
}
