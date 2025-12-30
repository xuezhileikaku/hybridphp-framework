<?php

declare(strict_types=1);

namespace HybridPHP\Core\GraphQL\Subscription;

use Amp\Future;
use Amp\DeferredFuture;
use HybridPHP\Core\GraphQL\Schema;
use HybridPHP\Core\GraphQL\Parser\Parser;
use HybridPHP\Core\GraphQL\Parser\DocumentNode;
use HybridPHP\Core\GraphQL\Parser\OperationDefinitionNode;
use HybridPHP\Core\GraphQL\Executor\Executor;
use function Amp\async;

/**
 * GraphQL Subscription Manager
 * Handles real-time subscriptions over WebSocket
 */
class SubscriptionManager
{
    protected Schema $schema;
    protected array $subscriptions = [];
    protected array $subscribers = [];
    protected ?PubSub $pubSub = null;

    public function __construct(Schema $schema, ?PubSub $pubSub = null)
    {
        $this->schema = $schema;
        $this->pubSub = $pubSub ?? new InMemoryPubSub();
    }

    /**
     * Subscribe to a GraphQL subscription
     *
     * @return string Subscription ID
     */
    public function subscribe(
        string $query,
        ?array $variables = null,
        ?string $operationName = null,
        mixed $context = null,
        callable $callback = null
    ): Future {
        return async(function () use ($query, $variables, $operationName, $context, $callback) {
            // Parse query
            $parser = new Parser($query);
            $document = $parser->parse();

            // Find subscription operation
            $operation = $this->findSubscriptionOperation($document, $operationName);
            if ($operation === null) {
                throw new \RuntimeException('No subscription operation found');
            }

            // Get subscription field
            $subscriptionType = $this->schema->getSubscriptionType();
            if ($subscriptionType === null) {
                throw new \RuntimeException('Schema does not support subscriptions');
            }

            $selections = $operation->selectionSet->selections;
            if (empty($selections)) {
                throw new \RuntimeException('Subscription must have at least one field');
            }

            // Get the subscription field name (topic)
            $fieldNode = $selections[0];
            if (!($fieldNode instanceof \HybridPHP\Core\GraphQL\Parser\FieldNode)) {
                throw new \RuntimeException('Invalid subscription selection');
            }

            $fieldName = $fieldNode->name;
            $fieldDef = $subscriptionType->getField($fieldName);
            if ($fieldDef === null) {
                throw new \RuntimeException("Unknown subscription field: {$fieldName}");
            }

            // Generate subscription ID
            $subscriptionId = $this->generateSubscriptionId();

            // Store subscription
            $this->subscriptions[$subscriptionId] = [
                'id' => $subscriptionId,
                'query' => $query,
                'variables' => $variables,
                'operationName' => $operationName,
                'context' => $context,
                'callback' => $callback,
                'document' => $document,
                'operation' => $operation,
                'fieldName' => $fieldName,
            ];

            // Subscribe to topic
            $topic = $this->getTopicForField($fieldName, $variables);
            $this->pubSub->subscribe($topic, function ($payload) use ($subscriptionId) {
                $this->executeSubscription($subscriptionId, $payload);
            });

            // Track subscriber
            if (!isset($this->subscribers[$topic])) {
                $this->subscribers[$topic] = [];
            }
            $this->subscribers[$topic][] = $subscriptionId;

            return $subscriptionId;
        });
    }

    /**
     * Unsubscribe from a subscription
     */
    public function unsubscribe(string $subscriptionId): void
    {
        if (!isset($this->subscriptions[$subscriptionId])) {
            return;
        }

        $subscription = $this->subscriptions[$subscriptionId];
        $topic = $this->getTopicForField($subscription['fieldName'], $subscription['variables']);

        // Remove from subscribers
        if (isset($this->subscribers[$topic])) {
            $this->subscribers[$topic] = array_filter(
                $this->subscribers[$topic],
                fn($id) => $id !== $subscriptionId
            );

            // Unsubscribe from topic if no more subscribers
            if (empty($this->subscribers[$topic])) {
                $this->pubSub->unsubscribe($topic);
                unset($this->subscribers[$topic]);
            }
        }

        unset($this->subscriptions[$subscriptionId]);
    }

    /**
     * Publish an event to subscribers
     */
    public function publish(string $topic, mixed $payload): void
    {
        $this->pubSub->publish($topic, $payload);
    }

    /**
     * Execute subscription for a payload
     */
    protected function executeSubscription(string $subscriptionId, mixed $payload): void
    {
        if (!isset($this->subscriptions[$subscriptionId])) {
            return;
        }

        $subscription = $this->subscriptions[$subscriptionId];

        async(function () use ($subscription, $payload) {
            try {
                $executor = new Executor($this->schema);
                $result = $executor->execute(
                    $subscription['document'],
                    $subscription['variables'],
                    $subscription['operationName'],
                    $payload,
                    $subscription['context']
                )->await();

                if ($subscription['callback'] !== null) {
                    ($subscription['callback'])($result);
                }
            } catch (\Throwable $e) {
                if ($subscription['callback'] !== null) {
                    ($subscription['callback'])([
                        'data' => null,
                        'errors' => [['message' => $e->getMessage()]],
                    ]);
                }
            }
        });
    }

    /**
     * Find subscription operation in document
     */
    protected function findSubscriptionOperation(
        DocumentNode $document,
        ?string $operationName
    ): ?OperationDefinitionNode {
        foreach ($document->definitions as $definition) {
            if (!($definition instanceof OperationDefinitionNode)) {
                continue;
            }

            if ($definition->operation !== 'subscription') {
                continue;
            }

            if ($operationName === null || $definition->name === $operationName) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * Get topic name for a subscription field
     */
    protected function getTopicForField(string $fieldName, ?array $variables): string
    {
        $topic = $fieldName;

        // Include relevant variables in topic for filtering
        if ($variables !== null && !empty($variables)) {
            $topic .= ':' . md5(json_encode($variables));
        }

        return $topic;
    }

    /**
     * Generate unique subscription ID
     */
    protected function generateSubscriptionId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Get all active subscriptions
     */
    public function getSubscriptions(): array
    {
        return $this->subscriptions;
    }

    /**
     * Get subscription count
     */
    public function getSubscriptionCount(): int
    {
        return count($this->subscriptions);
    }

    /**
     * Get PubSub instance
     */
    public function getPubSub(): PubSub
    {
        return $this->pubSub;
    }
}

/**
 * PubSub interface for subscription events
 */
interface PubSub
{
    /**
     * Subscribe to a topic
     */
    public function subscribe(string $topic, callable $callback): void;

    /**
     * Unsubscribe from a topic
     */
    public function unsubscribe(string $topic): void;

    /**
     * Publish to a topic
     */
    public function publish(string $topic, mixed $payload): void;
}

/**
 * In-memory PubSub implementation
 */
class InMemoryPubSub implements PubSub
{
    protected array $subscribers = [];

    public function subscribe(string $topic, callable $callback): void
    {
        if (!isset($this->subscribers[$topic])) {
            $this->subscribers[$topic] = [];
        }
        $this->subscribers[$topic][] = $callback;
    }

    public function unsubscribe(string $topic): void
    {
        unset($this->subscribers[$topic]);
    }

    public function publish(string $topic, mixed $payload): void
    {
        if (!isset($this->subscribers[$topic])) {
            return;
        }

        foreach ($this->subscribers[$topic] as $callback) {
            async(fn() => $callback($payload));
        }
    }
}
