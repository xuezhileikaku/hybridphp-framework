<?php

declare(strict_types=1);

namespace HybridPHP\Core\GraphQL\Http;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\RequestHandler;
use HybridPHP\Core\GraphQL\GraphQL;
use function Amp\async;

/**
 * HTTP Request Handler for GraphQL
 */
class GraphQLHandler implements RequestHandler
{
    protected GraphQL $graphql;
    protected array $options;

    public function __construct(GraphQL $graphql, array $options = [])
    {
        $this->graphql = $graphql;
        $this->options = array_merge([
            'debug' => false,
            'introspection' => true,
            'maxBatchSize' => 10,
            'maxQueryDepth' => 15,
            'maxQueryComplexity' => 100,
        ], $options);
    }

    /**
     * Handle HTTP request
     */
    public function handleRequest(Request $request): Response
    {
        $method = $request->getMethod();

        // Handle CORS preflight
        if ($method === 'OPTIONS') {
            return $this->createCorsResponse();
        }

        // Only allow GET and POST
        if (!in_array($method, ['GET', 'POST'])) {
            return $this->createErrorResponse(405, 'Method Not Allowed');
        }

        try {
            // Parse request
            $params = $this->parseRequest($request);

            // Check for batch request
            if (isset($params[0]) && is_array($params[0])) {
                return $this->handleBatchRequest($params, $request);
            }

            return $this->handleSingleRequest($params, $request);
        } catch (\Throwable $e) {
            return $this->createErrorResponse(400, $e->getMessage());
        }
    }

    /**
     * Handle single GraphQL request
     */
    protected function handleSingleRequest(array $params, Request $request): Response
    {
        $query = $params['query'] ?? '';
        $variables = $params['variables'] ?? null;
        $operationName = $params['operationName'] ?? null;

        if (empty($query)) {
            return $this->createErrorResponse(400, 'No query provided');
        }

        // Check introspection
        if (!$this->options['introspection'] && $this->isIntrospectionQuery($query)) {
            return $this->createErrorResponse(400, 'Introspection is disabled');
        }

        // Build context
        $context = $this->buildContext($request);

        // Execute query
        $result = $this->graphql->execute($query, $variables, $operationName, $context)->await();

        return $this->createJsonResponse($result);
    }

    /**
     * Handle batch GraphQL request
     */
    protected function handleBatchRequest(array $queries, Request $request): Response
    {
        if (count($queries) > $this->options['maxBatchSize']) {
            return $this->createErrorResponse(400, 'Batch size exceeds maximum');
        }

        $context = $this->buildContext($request);
        $results = $this->graphql->executeBatch($queries, $context)->await();

        return $this->createJsonResponse($results);
    }

    /**
     * Parse request parameters
     */
    protected function parseRequest(Request $request): array
    {
        $method = $request->getMethod();
        $contentType = $request->getHeader('Content-Type') ?? '';

        if ($method === 'GET') {
            $query = $request->getUri()->getQuery();
            parse_str($query, $params);
            
            if (isset($params['variables']) && is_string($params['variables'])) {
                $params['variables'] = json_decode($params['variables'], true);
            }
            
            return $params;
        }

        // POST request
        $body = $request->getBody()->buffer();

        if (str_contains($contentType, 'application/json')) {
            $params = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON body');
            }
            return $params ?? [];
        }

        if (str_contains($contentType, 'application/graphql')) {
            return ['query' => $body];
        }

        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($body, $params);
            
            if (isset($params['variables']) && is_string($params['variables'])) {
                $params['variables'] = json_decode($params['variables'], true);
            }
            
            return $params;
        }

        throw new \RuntimeException('Unsupported content type');
    }

    /**
     * Build execution context from request
     */
    protected function buildContext(Request $request): array
    {
        return [
            'request' => $request,
            'headers' => $request->getHeaders(),
            'clientIp' => $request->getClient()->getRemoteAddress()->toString(),
        ];
    }

    /**
     * Check if query is an introspection query
     */
    protected function isIntrospectionQuery(string $query): bool
    {
        return str_contains($query, '__schema') || str_contains($query, '__type');
    }

    /**
     * Create JSON response
     */
    protected function createJsonResponse(mixed $data, int $status = 200): Response
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

        return new Response(
            $status,
            [
                'Content-Type' => 'application/json',
                'Access-Control-Allow-Origin' => '*',
            ],
            $json
        );
    }

    /**
     * Create error response
     */
    protected function createErrorResponse(int $status, string $message): Response
    {
        $data = [
            'errors' => [
                ['message' => $message],
            ],
        ];

        if ($this->options['debug']) {
            $data['errors'][0]['extensions'] = [
                'code' => $status,
            ];
        }

        return $this->createJsonResponse($data, $status);
    }

    /**
     * Create CORS preflight response
     */
    protected function createCorsResponse(): Response
    {
        return new Response(
            204,
            [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
                'Access-Control-Max-Age' => '86400',
            ]
        );
    }
}
