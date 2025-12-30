<?php

declare(strict_types=1);

namespace HybridPHP\Core\GraphQL;

use Amp\Future;

/**
 * GraphQL executor interface for async operations
 */
interface GraphQLInterface
{
    /**
     * Execute a GraphQL query asynchronously
     *
     * @param string $query The GraphQL query string
     * @param array|null $variables Query variables
     * @param string|null $operationName Operation name for multi-operation documents
     * @param mixed $context Execution context (e.g., authenticated user)
     * @return Future<array> Resolves to ['data' => ..., 'errors' => ...]
     */
    public function execute(
        string $query,
        ?array $variables = null,
        ?string $operationName = null,
        mixed $context = null
    ): Future;

    /**
     * Get the schema
     */
    public function getSchema(): Schema;

    /**
     * Set the schema
     */
    public function setSchema(Schema $schema): void;
}
