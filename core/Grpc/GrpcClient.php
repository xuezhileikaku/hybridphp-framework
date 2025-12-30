<?php

declare(strict_types=1);

namespace HybridPHP\Core\Grpc;

use Amp\Future;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use HybridPHP\Core\Grpc\Protobuf\Codec;
use HybridPHP\Core\Grpc\Discovery\ServiceDiscoveryInterface;
use HybridPHP\Core\Grpc\Discovery\ServiceInstance;
use HybridPHP\Core\Grpc\LoadBalancer\LoadBalancerInterface;
use HybridPHP\Core\Grpc\LoadBalancer\RoundRobinLoadBalancer;
use function Amp\async;

/**
 * Async gRPC Client implementation
 */
class GrpcClient
{
    protected HttpClient $httpClient;
    protected LoggerInterface $logger;
    protected array $config;
    protected array $interceptors = [];
    protected ?ServiceDiscoveryInterface $serviceDiscovery = null;
    protected LoadBalancerInterface $loadBalancer;
    
    /** @var array<string, array<ServiceInstance>> */
    protected array $instanceCache = [];

    public function __construct(array $config = [], ?LoggerInterface $logger = null)
    {
        $this->config = array_merge([
            'host' => 'localhost',
            'port' => 50051,
            'timeout' => 30,
            'retries' => 3,
            'retryDelay' => 0.1,
            'compression' => null,
            'tls' => false,
            'metadata' => [],
        ], $config);

        $this->httpClient = HttpClientBuilder::buildDefault();
        $this->logger = $logger ?? new NullLogger();
        $this->loadBalancer = new RoundRobinLoadBalancer();
    }

    /**
     * Make a unary RPC call
     */
    public function unary(
        string $service,
        string $method,
        mixed $request,
        array $metadata = []
    ): Future {
        return async(function () use ($service, $method, $request, $metadata) {
            return $this->call($service, $method, $request, $metadata, MethodType::UNARY);
        });
    }

    /**
     * Make a server streaming RPC call
     *
     * @return Future<iterable>
     */
    public function serverStreaming(
        string $service,
        string $method,
        mixed $request,
        array $metadata = []
    ): Future {
        return async(function () use ($service, $method, $request, $metadata) {
            return $this->call($service, $method, $request, $metadata, MethodType::SERVER_STREAMING);
        });
    }

    /**
     * Make a client streaming RPC call
     */
    public function clientStreaming(
        string $service,
        string $method,
        iterable $requests,
        array $metadata = []
    ): Future {
        return async(function () use ($service, $method, $requests, $metadata) {
            // Collect all requests
            $requestData = '';
            foreach ($requests as $request) {
                $serialized = $this->serializeRequest($request);
                $requestData .= Codec::encodeWithLengthPrefix($serialized);
            }

            return $this->callWithData($service, $method, $requestData, $metadata);
        });
    }

    /**
     * Make a bidirectional streaming RPC call
     *
     * @return Future<iterable>
     */
    public function bidiStreaming(
        string $service,
        string $method,
        iterable $requests,
        array $metadata = []
    ): Future {
        return async(function () use ($service, $method, $requests, $metadata) {
            $requestData = '';
            foreach ($requests as $request) {
                $serialized = $this->serializeRequest($request);
                $requestData .= Codec::encodeWithLengthPrefix($serialized);
            }

            $response = $this->callWithData($service, $method, $requestData, $metadata);
            
            // Parse multiple responses
            return Codec::decodeMultiple($response);
        });
    }

    /**
     * Internal call implementation
     */
    protected function call(
        string $service,
        string $method,
        mixed $request,
        array $metadata,
        MethodType $type
    ): mixed {
        $serialized = $this->serializeRequest($request);
        $requestData = Codec::encodeWithLengthPrefix($serialized);

        $response = $this->callWithData($service, $method, $requestData, $metadata);

        if ($type === MethodType::SERVER_STREAMING) {
            return Codec::decodeMultiple($response);
        }

        $decoded = Codec::decodeWithLengthPrefix($response);
        return $decoded['compressed'] 
            ? Codec::decompress($decoded['data']) 
            : $decoded['data'];
    }

    /**
     * Make the actual HTTP/2 call
     */
    protected function callWithData(
        string $service,
        string $method,
        string $requestData,
        array $metadata
    ): string {
        $instance = $this->selectInstance($service);
        $url = $this->buildUrl($instance, $service, $method);

        $retries = $this->config['retries'];
        $lastError = null;

        while ($retries >= 0) {
            $startTime = microtime(true);

            try {
                $request = new Request($url, 'POST');
                $request->setBody($requestData);
                $request->setHeader('Content-Type', 'application/grpc+proto');
                $request->setHeader('TE', 'trailers');

                // Add metadata as headers
                foreach (array_merge($this->config['metadata'], $metadata) as $key => $value) {
                    $request->setHeader($key, $value);
                }

                // Apply interceptors
                $context = new Context($metadata);
                foreach ($this->interceptors as $interceptor) {
                    $interceptor->intercept($request, $context, fn($r, $c) => $r);
                }

                $response = $this->httpClient->request($request);
                $latency = microtime(true) - $startTime;

                // Check gRPC status
                $grpcStatus = (int)($response->getHeader('grpc-status') ?? 0);
                
                if ($grpcStatus !== 0) {
                    $grpcMessage = rawurldecode($response->getHeader('grpc-message') ?? '');
                    throw new GrpcException($grpcMessage, Status::from($grpcStatus));
                }

                $this->loadBalancer->reportSuccess($instance, $latency);

                return $response->getBody()->buffer();

            } catch (GrpcException $e) {
                $lastError = $e;
                $this->loadBalancer->reportFailure($instance, $e);

                // Don't retry on certain status codes
                if (in_array($e->getStatus(), [
                    Status::INVALID_ARGUMENT,
                    Status::NOT_FOUND,
                    Status::ALREADY_EXISTS,
                    Status::PERMISSION_DENIED,
                    Status::UNAUTHENTICATED,
                    Status::UNIMPLEMENTED,
                ])) {
                    throw $e;
                }

                $retries--;
                if ($retries >= 0) {
                    \Amp\delay($this->config['retryDelay']);
                    $instance = $this->selectInstance($service);
                    $url = $this->buildUrl($instance, $service, $method);
                }

            } catch (\Throwable $e) {
                $lastError = $e;
                $this->loadBalancer->reportFailure($instance, $e);
                
                $retries--;
                if ($retries >= 0) {
                    \Amp\delay($this->config['retryDelay']);
                    $instance = $this->selectInstance($service);
                    $url = $this->buildUrl($instance, $service, $method);
                }
            }
        }

        throw $lastError ?? GrpcException::unavailable('Service unavailable');
    }

    /**
     * Select a service instance
     */
    protected function selectInstance(string $service): ServiceInstance
    {
        if ($this->serviceDiscovery) {
            $instances = $this->serviceDiscovery->discover($service)->await();
            
            if (empty($instances)) {
                throw GrpcException::unavailable("No instances available for service: {$service}");
            }

            $selected = $this->loadBalancer->select($instances);
            
            if (!$selected) {
                throw GrpcException::unavailable("No healthy instances for service: {$service}");
            }

            return $selected;
        }

        // Use configured host/port
        return new ServiceInstance(
            id: 'default',
            serviceName: $service,
            host: $this->config['host'],
            port: $this->config['port'],
        );
    }

    /**
     * Build the URL for a gRPC call
     */
    protected function buildUrl(ServiceInstance $instance, string $service, string $method): string
    {
        $scheme = $this->config['tls'] ? 'https' : 'http';
        return sprintf('%s://%s:%d/%s/%s', $scheme, $instance->host, $instance->port, $service, $method);
    }

    /**
     * Serialize a request
     */
    protected function serializeRequest(mixed $request): string
    {
        if ($request instanceof Protobuf\MessageInterface) {
            return $request->serializeToString();
        }

        if (is_string($request)) {
            return $request;
        }

        return json_encode($request);
    }

    /**
     * Add an interceptor
     */
    public function addInterceptor(InterceptorInterface $interceptor): self
    {
        $this->interceptors[] = $interceptor;
        return $this;
    }

    /**
     * Set service discovery
     */
    public function setServiceDiscovery(ServiceDiscoveryInterface $discovery): self
    {
        $this->serviceDiscovery = $discovery;
        return $this;
    }

    /**
     * Set load balancer
     */
    public function setLoadBalancer(LoadBalancerInterface $loadBalancer): self
    {
        $this->loadBalancer = $loadBalancer;
        return $this;
    }

    /**
     * Set default metadata
     */
    public function setMetadata(array $metadata): self
    {
        $this->config['metadata'] = $metadata;
        return $this;
    }

    /**
     * Create a client for a specific service
     */
    public function forService(string $service): ServiceClient
    {
        return new ServiceClient($this, $service);
    }
}
