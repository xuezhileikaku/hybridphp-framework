<?php

declare(strict_types=1);

namespace HybridPHP\Core\GraphQL\Http;

use HybridPHP\Core\GraphQL\GraphQL;
use HybridPHP\Core\GraphQL\Subscription\SubscriptionManager;
use function Amp\async;

/**
 * WebSocket Handler for GraphQL Subscriptions
 * Implements the graphql-ws protocol
 */
class GraphQLWebSocketHandler
{
    protected GraphQL $graphql;
    protected SubscriptionManager $subscriptionManager;
    protected array $connections = [];
    protected array $options;

    // Protocol message types (graphql-ws)
    public const GQL_CONNECTION_INIT = 'connection_init';
    public const GQL_CONNECTION_ACK = 'connection_ack';
    public const GQL_CONNECTION_ERROR = 'connection_error';
    public const GQL_CONNECTION_KEEP_ALIVE = 'ka';
    public const GQL_CONNECTION_TERMINATE = 'connection_terminate';
    public const GQL_SUBSCRIBE = 'subscribe';
    public const GQL_NEXT = 'next';
    public const GQL_ERROR = 'error';
    public const GQL_COMPLETE = 'complete';
    public const GQL_PING = 'ping';
    public const GQL_PONG = 'pong';

    public function __construct(GraphQL $graphql, array $options = [])
    {
        $this->graphql = $graphql;
        $this->subscriptionManager = $graphql->getSubscriptionManager();
        $this->options = array_merge([
            'keepAlive' => 30000, // 30 seconds
            'connectionTimeout' => 5000, // 5 seconds
        ], $options);
    }

    /**
     * Handle new WebSocket connection
     */
    public function onConnect(string $connectionId, callable $send): void
    {
        $this->connections[$connectionId] = [
            'id' => $connectionId,
            'send' => $send,
            'initialized' => false,
            'subscriptions' => [],
            'context' => null,
        ];
    }

    /**
     * Handle WebSocket message
     */
    public function onMessage(string $connectionId, string $message): void
    {
        if (!isset($this->connections[$connectionId])) {
            return;
        }

        $connection = &$this->connections[$connectionId];
        $data = json_decode($message, true);

        if (!is_array($data) || !isset($data['type'])) {
            $this->sendError($connectionId, null, 'Invalid message format');
            return;
        }

        $type = $data['type'];
        $id = $data['id'] ?? null;
        $payload = $data['payload'] ?? [];

        switch ($type) {
            case self::GQL_CONNECTION_INIT:
                $this->handleConnectionInit($connectionId, $payload);
                break;

            case self::GQL_SUBSCRIBE:
                $this->handleSubscribe($connectionId, $id, $payload);
                break;

            case self::GQL_COMPLETE:
                $this->handleComplete($connectionId, $id);
                break;

            case self::GQL_CONNECTION_TERMINATE:
                $this->handleTerminate($connectionId);
                break;

            case self::GQL_PING:
                $this->send($connectionId, ['type' => self::GQL_PONG]);
                break;

            default:
                $this->sendError($connectionId, $id, "Unknown message type: {$type}");
        }
    }

    /**
     * Handle WebSocket disconnect
     */
    public function onDisconnect(string $connectionId): void
    {
        if (!isset($this->connections[$connectionId])) {
            return;
        }

        // Unsubscribe all subscriptions
        foreach ($this->connections[$connectionId]['subscriptions'] as $subscriptionId) {
            $this->subscriptionManager->unsubscribe($subscriptionId);
        }

        unset($this->connections[$connectionId]);
    }

    /**
     * Handle connection init
     */
    protected function handleConnectionInit(string $connectionId, array $payload): void
    {
        $connection = &$this->connections[$connectionId];

        // Store connection params as context
        $connection['context'] = $payload;
        $connection['initialized'] = true;

        // Send acknowledgment
        $this->send($connectionId, ['type' => self::GQL_CONNECTION_ACK]);

        // Start keep-alive if configured
        if ($this->options['keepAlive'] > 0) {
            $this->startKeepAlive($connectionId);
        }
    }

    /**
     * Handle subscribe
     */
    protected function handleSubscribe(string $connectionId, ?string $id, array $payload): void
    {
        if ($id === null) {
            $this->sendError($connectionId, null, 'Subscribe requires an id');
            return;
        }

        $connection = &$this->connections[$connectionId];

        if (!$connection['initialized']) {
            $this->sendError($connectionId, $id, 'Connection not initialized');
            return;
        }

        $query = $payload['query'] ?? '';
        $variables = $payload['variables'] ?? null;
        $operationName = $payload['operationName'] ?? null;

        async(function () use ($connectionId, $id, $query, $variables, $operationName, &$connection) {
            try {
                $subscriptionId = $this->subscriptionManager->subscribe(
                    $query,
                    $variables,
                    $operationName,
                    $connection['context'],
                    function ($result) use ($connectionId, $id) {
                        $this->send($connectionId, [
                            'id' => $id,
                            'type' => self::GQL_NEXT,
                            'payload' => $result,
                        ]);
                    }
                )->await();

                $connection['subscriptions'][$id] = $subscriptionId;
            } catch (\Throwable $e) {
                $this->sendError($connectionId, $id, $e->getMessage());
            }
        });
    }

    /**
     * Handle complete (unsubscribe)
     */
    protected function handleComplete(string $connectionId, ?string $id): void
    {
        if ($id === null) {
            return;
        }

        $connection = &$this->connections[$connectionId];

        if (isset($connection['subscriptions'][$id])) {
            $subscriptionId = $connection['subscriptions'][$id];
            $this->subscriptionManager->unsubscribe($subscriptionId);
            unset($connection['subscriptions'][$id]);
        }
    }

    /**
     * Handle terminate
     */
    protected function handleTerminate(string $connectionId): void
    {
        $this->onDisconnect($connectionId);
    }

    /**
     * Send message to connection
     */
    protected function send(string $connectionId, array $message): void
    {
        if (!isset($this->connections[$connectionId])) {
            return;
        }

        $send = $this->connections[$connectionId]['send'];
        $send(json_encode($message));
    }

    /**
     * Send error to connection
     */
    protected function sendError(string $connectionId, ?string $id, string $message): void
    {
        $this->send($connectionId, [
            'id' => $id,
            'type' => self::GQL_ERROR,
            'payload' => [
                ['message' => $message],
            ],
        ]);
    }

    /**
     * Start keep-alive for connection
     */
    protected function startKeepAlive(string $connectionId): void
    {
        async(function () use ($connectionId) {
            while (isset($this->connections[$connectionId])) {
                \Amp\delay($this->options['keepAlive'] / 1000);
                
                if (isset($this->connections[$connectionId])) {
                    $this->send($connectionId, ['type' => self::GQL_CONNECTION_KEEP_ALIVE]);
                }
            }
        });
    }

    /**
     * Get connection count
     */
    public function getConnectionCount(): int
    {
        return count($this->connections);
    }

    /**
     * Get total subscription count
     */
    public function getSubscriptionCount(): int
    {
        $count = 0;
        foreach ($this->connections as $connection) {
            $count += count($connection['subscriptions']);
        }
        return $count;
    }
}
