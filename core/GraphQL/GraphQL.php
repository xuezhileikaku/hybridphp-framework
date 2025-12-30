<?php

declare(strict_types=1);

namespace HybridPHP\Core\GraphQL;

use Amp\Future;
use HybridPHP\Core\GraphQL\Executor\Executor;
use HybridPHP\Core\GraphQL\Subscription\SubscriptionManager;
use HybridPHP\Core\GraphQL\DataLoader\DataLoader;
use function Amp\async;

/**
 * Main GraphQL class - facade for GraphQL operations
 */
class GraphQL implements GraphQLInterface
{
    protected Schema $schema;
    protected ?SubscriptionManager $subscriptionManager = null;
    protected array $dataLoaders = [];
    protected array $middleware = [];

    public function __construct(Schema $schema)
    {
        $this->schema = $schema;
    }

    /**
     * Execute a GraphQL query asynchronously
     */
    public function execute(
        string $query,
        ?array $variables = null,
        ?string $operationName = null,
        mixed $context = null
    ): Future {
        return async(function () use ($query, $variables, $operationName, $context) {
            // Apply middleware
            $context = $this->applyMiddleware($context);

            // Inject data loaders into context
            if (!empty($this->dataLoaders)) {
                if (is_array($context)) {
                    $context['dataLoaders'] = $this->dataLoaders;
                } elseif (is_object($context)) {
                    $context->dataLoaders = $this->dataLoaders;
                } else {
                    $context = ['dataLoaders' => $this->dataLoaders];
                }
            }

            $executor = new Executor($this->schema);
            return $executor->execute(
                $query,
                $variables,
                $operationName,
                null,
                $context
            )->await();
        });
    }

    /**
     * Execute a batch of GraphQL queries
     */
    public function executeBatch(array $queries, mixed $context = null): Future
    {
        return async(function () use ($queries, $context) {
            $futures = [];

            foreach ($queries as $query) {
                $futures[] = $this->execute(
                    $query['query'] ?? '',
                    $query['variables'] ?? null,
                    $query['operationName'] ?? null,
                    $context
                );
            }

            $results = [];
            foreach ($futures as $future) {
                $results[] = $future->await();
            }

            return $results;
        });
    }

    /**
     * Get the schema
     */
    public function getSchema(): Schema
    {
        return $this->schema;
    }

    /**
     * Set the schema
     */
    public function setSchema(Schema $schema): void
    {
        $this->schema = $schema;
    }

    /**
     * Get subscription manager
     */
    public function getSubscriptionManager(): SubscriptionManager
    {
        if ($this->subscriptionManager === null) {
            $this->subscriptionManager = new SubscriptionManager($this->schema);
        }
        return $this->subscriptionManager;
    }

    /**
     * Set subscription manager
     */
    public function setSubscriptionManager(SubscriptionManager $manager): void
    {
        $this->subscriptionManager = $manager;
    }

    /**
     * Register a data loader
     */
    public function registerDataLoader(string $name, DataLoader $loader): void
    {
        $this->dataLoaders[$name] = $loader;
    }

    /**
     * Get a data loader
     */
    public function getDataLoader(string $name): ?DataLoader
    {
        return $this->dataLoaders[$name] ?? null;
    }

    /**
     * Add middleware
     */
    public function addMiddleware(callable $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    /**
     * Apply middleware to context
     */
    protected function applyMiddleware(mixed $context): mixed
    {
        foreach ($this->middleware as $middleware) {
            $context = $middleware($context);
        }
        return $context;
    }

    /**
     * Validate a query without executing
     */
    public function validate(string $query): array
    {
        try {
            $parser = new Parser\Parser($query);
            $document = $parser->parse();
            
            // Basic validation
            $errors = [];
            
            foreach ($document->definitions as $definition) {
                if ($definition instanceof Parser\OperationDefinitionNode) {
                    $rootType = match ($definition->operation) {
                        'query' => $this->schema->getQueryType(),
                        'mutation' => $this->schema->getMutationType(),
                        'subscription' => $this->schema->getSubscriptionType(),
                        default => null,
                    };

                    if ($rootType === null) {
                        $errors[] = [
                            'message' => "Schema does not support {$definition->operation}",
                        ];
                    }
                }
            }

            return $errors;
        } catch (\Throwable $e) {
            return [['message' => $e->getMessage()]];
        }
    }

    /**
     * Get introspection query result
     */
    public function introspect(): Future
    {
        $introspectionQuery = <<<'GRAPHQL'
        query IntrospectionQuery {
            __schema {
                queryType { name }
                mutationType { name }
                subscriptionType { name }
                types { name description }
            }
        }
        GRAPHQL;

        return $this->execute($introspectionQuery);
    }
}
