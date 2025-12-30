<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use HybridPHP\Core\Grpc\GrpcServer;
use HybridPHP\Core\Grpc\GrpcClient;
use HybridPHP\Core\Grpc\Context;
use HybridPHP\Core\Grpc\Status;
use HybridPHP\Core\Grpc\MethodType;
use HybridPHP\Core\Grpc\GrpcException;
use HybridPHP\Core\Grpc\ServiceInterface;
use HybridPHP\Core\Grpc\InterceptorInterface;
use HybridPHP\Core\Grpc\Protobuf\AbstractMessage;
use HybridPHP\Core\Grpc\Protobuf\Codec;
use HybridPHP\Core\Grpc\Discovery\InMemoryServiceDiscovery;
use HybridPHP\Core\Grpc\Discovery\ServiceInstance;
use HybridPHP\Core\Grpc\LoadBalancer\RoundRobinLoadBalancer;
use HybridPHP\Core\Grpc\LoadBalancer\WeightedLoadBalancer;
use HybridPHP\Core\Grpc\LoadBalancer\LeastConnectionsLoadBalancer;
use HybridPHP\Core\Grpc\LoadBalancer\ConsistentHashLoadBalancer;
use HybridPHP\Core\Grpc\Interceptors\LoggingInterceptor;
use HybridPHP\Core\Grpc\Interceptors\MetricsInterceptor;
use HybridPHP\Core\Grpc\Interceptors\RetryInterceptor;

/**
 * gRPC unit tests
 */
class GrpcTest extends TestCase
{
    // ========================================================================
    // Status Tests
    // ========================================================================

    public function testStatusCodes(): void
    {
        $this->assertEquals(0, Status::OK->value);
        $this->assertEquals(1, Status::CANCELLED->value);
        $this->assertEquals(2, Status::UNKNOWN->value);
        $this->assertEquals(3, Status::INVALID_ARGUMENT->value);
        $this->assertEquals(12, Status::UNIMPLEMENTED->value);
        $this->assertEquals(13, Status::INTERNAL->value);
        $this->assertEquals(14, Status::UNAVAILABLE->value);
        $this->assertEquals(16, Status::UNAUTHENTICATED->value);
    }

    public function testStatusIsOk(): void
    {
        $this->assertTrue(Status::OK->isOk());
        $this->assertFalse(Status::INTERNAL->isOk());
    }

    public function testStatusIsError(): void
    {
        $this->assertFalse(Status::OK->isError());
        $this->assertTrue(Status::INTERNAL->isError());
    }

    public function testStatusMessage(): void
    {
        $this->assertEquals('OK', Status::OK->getMessage());
        $this->assertEquals('Internal error', Status::INTERNAL->getMessage());
        $this->assertEquals('Unauthenticated', Status::UNAUTHENTICATED->getMessage());
    }

    // ========================================================================
    // GrpcException Tests
    // ========================================================================

    public function testGrpcExceptionCreation(): void
    {
        $exception = new GrpcException('Test error', Status::INTERNAL);

        $this->assertEquals('Test error', $exception->getMessage());
        $this->assertEquals(Status::INTERNAL, $exception->getStatus());
        $this->assertEquals(13, $exception->getCode());
    }

    public function testGrpcExceptionFactoryMethods(): void
    {
        $this->assertEquals(Status::INVALID_ARGUMENT, GrpcException::invalidArgument('test')->getStatus());
        $this->assertEquals(Status::NOT_FOUND, GrpcException::notFound('test')->getStatus());
        $this->assertEquals(Status::UNIMPLEMENTED, GrpcException::unimplemented('test')->getStatus());
        $this->assertEquals(Status::INTERNAL, GrpcException::internal('test')->getStatus());
        $this->assertEquals(Status::PERMISSION_DENIED, GrpcException::permissionDenied('test')->getStatus());
        $this->assertEquals(Status::UNAUTHENTICATED, GrpcException::unauthenticated('test')->getStatus());
        $this->assertEquals(Status::UNAVAILABLE, GrpcException::unavailable('test')->getStatus());
    }

    public function testGrpcExceptionWithDetails(): void
    {
        $details = ['field' => 'name', 'reason' => 'required'];
        $exception = new GrpcException('Validation failed', Status::INVALID_ARGUMENT, $details);

        $this->assertEquals($details, $exception->getDetails());
    }

    // ========================================================================
    // Context Tests
    // ========================================================================

    public function testContextMetadata(): void
    {
        $context = new Context(['key' => 'value']);

        $this->assertEquals(['key' => 'value'], $context->getMetadata());
        $this->assertEquals('value', $context->getMetadataValue('key'));
        $this->assertNull($context->getMetadataValue('nonexistent'));
    }

    public function testContextSetMetadata(): void
    {
        $context = new Context();
        $context->setMetadata('key', 'value');

        $this->assertEquals('value', $context->getMetadataValue('key'));
    }

    public function testContextDeadline(): void
    {
        $deadline = microtime(true) + 10;
        $context = new Context([], $deadline);

        $this->assertEquals($deadline, $context->getDeadline());
        $this->assertFalse($context->isDeadlineExceeded());
        $this->assertGreaterThan(0, $context->getRemainingTime());
    }

    public function testContextDeadlineExceeded(): void
    {
        $deadline = microtime(true) - 1; // Past deadline
        $context = new Context([], $deadline);

        $this->assertTrue($context->isDeadlineExceeded());
        $this->assertEquals(0, $context->getRemainingTime());
    }

    public function testContextCancellation(): void
    {
        $context = new Context();

        $this->assertFalse($context->isCancelled());
        
        $context->cancel();
        
        $this->assertTrue($context->isCancelled());
    }

    public function testContextValues(): void
    {
        $context = new Context();
        $context->setValue('user', ['id' => 1, 'name' => 'Test']);

        $this->assertEquals(['id' => 1, 'name' => 'Test'], $context->getValue('user'));
        $this->assertNull($context->getValue('nonexistent'));
    }

    public function testContextAuthToken(): void
    {
        $context = new Context(['authorization' => 'Bearer token123']);

        $this->assertEquals('token123', $context->getAuthToken());
    }

    public function testContextWithMetadata(): void
    {
        $context = new Context(['key1' => 'value1']);
        $child = $context->withMetadata(['key2' => 'value2']);

        $this->assertEquals('value1', $child->getMetadataValue('key1'));
        $this->assertEquals('value2', $child->getMetadataValue('key2'));
    }

    public function testContextWithTimeout(): void
    {
        $context = new Context();
        $child = $context->withTimeout(5.0);

        $this->assertNotNull($child->getDeadline());
        $this->assertLessThanOrEqual(5.0, $child->getRemainingTime());
    }

    // ========================================================================
    // Protobuf Codec Tests
    // ========================================================================

    public function testCodecEncodeWithLengthPrefix(): void
    {
        $data = 'Hello, World!';
        $encoded = Codec::encodeWithLengthPrefix($data);

        // First byte is compression flag (0 = not compressed)
        $this->assertEquals(0, ord($encoded[0]));
        
        // Next 4 bytes are length in big-endian
        $length = unpack('N', substr($encoded, 1, 4))[1];
        $this->assertEquals(strlen($data), $length);
        
        // Rest is the data
        $this->assertEquals($data, substr($encoded, 5));
    }

    public function testCodecDecodeWithLengthPrefix(): void
    {
        $data = 'Hello, World!';
        $encoded = Codec::encodeWithLengthPrefix($data);
        $decoded = Codec::decodeWithLengthPrefix($encoded);

        $this->assertFalse($decoded['compressed']);
        $this->assertEquals($data, $decoded['data']);
        $this->assertEquals('', $decoded['remaining']);
    }

    public function testCodecEncodeMultiple(): void
    {
        $messages = ['Message 1', 'Message 2', 'Message 3'];
        $encoded = Codec::encodeMultiple($messages);

        $decoded = Codec::decodeMultiple($encoded);

        $this->assertCount(3, $decoded);
        $this->assertEquals('Message 1', $decoded[0]['data']);
        $this->assertEquals('Message 2', $decoded[1]['data']);
        $this->assertEquals('Message 3', $decoded[2]['data']);
    }

    public function testCodecCompression(): void
    {
        $data = str_repeat('Hello, World! ', 100);
        $compressed = Codec::compress($data);
        $decompressed = Codec::decompress($compressed);

        $this->assertEquals($data, $decompressed);
        $this->assertLessThan(strlen($data), strlen($compressed));
    }

    // ========================================================================
    // Service Discovery Tests
    // ========================================================================

    public function testInMemoryServiceDiscoveryRegister(): void
    {
        $discovery = new InMemoryServiceDiscovery();

        $result = $discovery->register('test.Service', [
            'id' => 'instance-1',
            'host' => 'localhost',
            'port' => 50051,
        ])->await();

        $this->assertTrue($result);
    }

    public function testInMemoryServiceDiscoveryDiscover(): void
    {
        $discovery = new InMemoryServiceDiscovery();

        $discovery->register('test.Service', [
            'id' => 'instance-1',
            'host' => 'localhost',
            'port' => 50051,
        ])->await();

        $instances = $discovery->discover('test.Service')->await();

        $this->assertCount(1, $instances);
        $this->assertEquals('instance-1', $instances[0]->id);
        $this->assertEquals('localhost', $instances[0]->host);
        $this->assertEquals(50051, $instances[0]->port);
    }

    public function testInMemoryServiceDiscoveryDeregister(): void
    {
        $discovery = new InMemoryServiceDiscovery();

        $discovery->register('test.Service', [
            'id' => 'instance-1',
            'host' => 'localhost',
            'port' => 50051,
        ])->await();

        $discovery->deregister('test.Service', 'instance-1')->await();

        $instances = $discovery->discover('test.Service')->await();
        $this->assertCount(0, $instances);
    }

    public function testInMemoryServiceDiscoveryGetServices(): void
    {
        $discovery = new InMemoryServiceDiscovery();

        $discovery->register('service.A', ['host' => 'localhost', 'port' => 50051])->await();
        $discovery->register('service.B', ['host' => 'localhost', 'port' => 50052])->await();

        $services = $discovery->getServices()->await();

        $this->assertContains('service.A', $services);
        $this->assertContains('service.B', $services);
    }

    public function testInMemoryServiceDiscoveryHealthCheck(): void
    {
        $discovery = new InMemoryServiceDiscovery();

        $discovery->register('test.Service', [
            'id' => 'instance-1',
            'host' => 'localhost',
            'port' => 50051,
        ])->await();

        $healthy = $discovery->healthCheck('test.Service', 'instance-1')->await();
        $this->assertTrue($healthy);

        // Update health to unhealthy
        $discovery->updateHealth('test.Service', 'instance-1', false);

        $healthy = $discovery->healthCheck('test.Service', 'instance-1')->await();
        $this->assertFalse($healthy);
    }

    // ========================================================================
    // Service Instance Tests
    // ========================================================================

    public function testServiceInstanceCreation(): void
    {
        $instance = new ServiceInstance(
            id: 'test-1',
            serviceName: 'test.Service',
            host: 'localhost',
            port: 50051,
            metadata: ['version' => '1.0'],
            healthy: true,
            weight: 100,
            zone: 'us-east-1'
        );

        $this->assertEquals('test-1', $instance->id);
        $this->assertEquals('test.Service', $instance->serviceName);
        $this->assertEquals('localhost', $instance->host);
        $this->assertEquals(50051, $instance->port);
        $this->assertEquals(['version' => '1.0'], $instance->metadata);
        $this->assertTrue($instance->healthy);
        $this->assertEquals(100, $instance->weight);
        $this->assertEquals('us-east-1', $instance->zone);
    }

    public function testServiceInstanceGetAddress(): void
    {
        $instance = new ServiceInstance(
            id: 'test-1',
            serviceName: 'test.Service',
            host: 'localhost',
            port: 50051
        );

        $this->assertEquals('localhost:50051', $instance->getAddress());
    }

    public function testServiceInstanceFromArray(): void
    {
        $instance = ServiceInstance::fromArray([
            'id' => 'test-1',
            'serviceName' => 'test.Service',
            'host' => 'localhost',
            'port' => 50051,
        ]);

        $this->assertEquals('test-1', $instance->id);
        $this->assertEquals('test.Service', $instance->serviceName);
    }

    public function testServiceInstanceToArray(): void
    {
        $instance = new ServiceInstance(
            id: 'test-1',
            serviceName: 'test.Service',
            host: 'localhost',
            port: 50051
        );

        $array = $instance->toArray();

        $this->assertEquals('test-1', $array['id']);
        $this->assertEquals('test.Service', $array['serviceName']);
        $this->assertEquals('localhost', $array['host']);
        $this->assertEquals(50051, $array['port']);
    }

    // ========================================================================
    // Load Balancer Tests
    // ========================================================================

    public function testRoundRobinLoadBalancer(): void
    {
        $lb = new RoundRobinLoadBalancer();

        $instances = [
            new ServiceInstance('1', 'svc', 'host1', 50051),
            new ServiceInstance('2', 'svc', 'host2', 50052),
            new ServiceInstance('3', 'svc', 'host3', 50053),
        ];

        $selected1 = $lb->select($instances);
        $selected2 = $lb->select($instances);
        $selected3 = $lb->select($instances);
        $selected4 = $lb->select($instances);

        // Should cycle through instances
        $this->assertEquals('1', $selected1->id);
        $this->assertEquals('2', $selected2->id);
        $this->assertEquals('3', $selected3->id);
        $this->assertEquals('1', $selected4->id); // Wraps around
    }

    public function testRoundRobinLoadBalancerEmptyInstances(): void
    {
        $lb = new RoundRobinLoadBalancer();
        $this->assertNull($lb->select([]));
    }

    public function testRoundRobinLoadBalancerSkipsUnhealthy(): void
    {
        $lb = new RoundRobinLoadBalancer();

        $instances = [
            new ServiceInstance('1', 'svc', 'host1', 50051, [], false), // unhealthy
            new ServiceInstance('2', 'svc', 'host2', 50052, [], true),
        ];

        $selected = $lb->select($instances);
        $this->assertEquals('2', $selected->id);
    }

    public function testWeightedLoadBalancer(): void
    {
        $lb = new WeightedLoadBalancer();

        $instances = [
            new ServiceInstance('1', 'svc', 'host1', 50051, [], true, 100),
            new ServiceInstance('2', 'svc', 'host2', 50052, [], true, 0),
        ];

        // With weight 0, instance 2 should rarely be selected
        $counts = ['1' => 0, '2' => 0];
        for ($i = 0; $i < 100; $i++) {
            $selected = $lb->select($instances);
            $counts[$selected->id]++;
        }

        $this->assertGreaterThan($counts['2'], $counts['1']);
    }

    public function testLeastConnectionsLoadBalancer(): void
    {
        $lb = new LeastConnectionsLoadBalancer();

        $instances = [
            new ServiceInstance('1', 'svc', 'host1', 50051),
            new ServiceInstance('2', 'svc', 'host2', 50052),
        ];

        // First selection
        $selected1 = $lb->select($instances);
        $this->assertEquals('1', $selected1->id);

        // Second selection should pick instance 2 (least connections)
        $selected2 = $lb->select($instances);
        $this->assertEquals('2', $selected2->id);

        // Report success to decrement connection count
        $lb->reportSuccess($selected1, 0.1);

        // Now instance 1 should be selected again
        $selected3 = $lb->select($instances);
        $this->assertEquals('1', $selected3->id);
    }

    public function testConsistentHashLoadBalancer(): void
    {
        $lb = new ConsistentHashLoadBalancer();

        $instances = [
            new ServiceInstance('1', 'svc', 'host1', 50051),
            new ServiceInstance('2', 'svc', 'host2', 50052),
            new ServiceInstance('3', 'svc', 'host3', 50053),
        ];

        // Same key should always select same instance
        $selected1 = $lb->select($instances, ['hash_key' => 'user-123']);
        $selected2 = $lb->select($instances, ['hash_key' => 'user-123']);
        $selected3 = $lb->select($instances, ['hash_key' => 'user-123']);

        $this->assertEquals($selected1->id, $selected2->id);
        $this->assertEquals($selected2->id, $selected3->id);
    }

    // ========================================================================
    // Interceptor Tests
    // ========================================================================

    public function testLoggingInterceptor(): void
    {
        $interceptor = new LoggingInterceptor();
        $context = new Context(['x-request-id' => 'test-123']);

        $result = $interceptor->intercept(
            'request',
            $context,
            fn($req, $ctx) => 'response'
        );

        $this->assertEquals('response', $result);
    }

    public function testMetricsInterceptor(): void
    {
        $interceptor = new MetricsInterceptor();
        $context = new Context();
        $context->setValue('method', 'TestMethod');

        // Successful call
        $interceptor->intercept('request', $context, fn($req, $ctx) => 'response');

        $metrics = $interceptor->getMetrics();

        $this->assertEquals(1, $metrics['requests_total']);
        $this->assertEquals(1, $metrics['requests_success']);
        $this->assertEquals(0, $metrics['requests_failed']);
    }

    public function testMetricsInterceptorFailure(): void
    {
        $interceptor = new MetricsInterceptor();
        $context = new Context();
        $context->setValue('method', 'TestMethod');

        try {
            $interceptor->intercept('request', $context, function ($req, $ctx) {
                throw GrpcException::internal('Test error');
            });
        } catch (GrpcException $e) {
            // Expected
        }

        $metrics = $interceptor->getMetrics();

        $this->assertEquals(1, $metrics['requests_total']);
        $this->assertEquals(0, $metrics['requests_success']);
        $this->assertEquals(1, $metrics['requests_failed']);
    }

    public function testMetricsInterceptorPrometheusFormat(): void
    {
        $interceptor = new MetricsInterceptor();
        $context = new Context();
        $context->setValue('method', 'TestMethod');

        $interceptor->intercept('request', $context, fn($req, $ctx) => 'response');

        $prometheus = $interceptor->getPrometheusMetrics();

        $this->assertStringContainsString('grpc_requests_total', $prometheus);
        $this->assertStringContainsString('grpc_requests_success_total', $prometheus);
    }

    public function testRetryInterceptor(): void
    {
        $attempts = 0;
        $interceptor = new RetryInterceptor(maxRetries: 3, initialDelay: 0.01);
        $context = new Context();

        $result = $interceptor->intercept('request', $context, function ($req, $ctx) use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw GrpcException::unavailable('Temporary failure');
            }
            return 'success';
        });

        $this->assertEquals('success', $result);
        $this->assertEquals(3, $attempts);
    }

    public function testRetryInterceptorNoRetryOnCertainStatuses(): void
    {
        $attempts = 0;
        $interceptor = new RetryInterceptor(maxRetries: 3);
        $context = new Context();

        $this->expectException(GrpcException::class);

        $interceptor->intercept('request', $context, function ($req, $ctx) use (&$attempts) {
            $attempts++;
            throw GrpcException::invalidArgument('Bad request');
        });

        // Should not retry on INVALID_ARGUMENT
        $this->assertEquals(1, $attempts);
    }

    // ========================================================================
    // GrpcServer Tests
    // ========================================================================

    public function testGrpcServerServiceRegistration(): void
    {
        $server = new GrpcServer();

        $service = new class implements ServiceInterface {
            public function getServiceName(): string
            {
                return 'test.Service';
            }

            public function getMethods(): array
            {
                return [
                    'TestMethod' => [
                        'type' => MethodType::UNARY,
                        'request' => 'bytes',
                        'response' => 'bytes',
                    ],
                ];
            }
        };

        $server->registerService('test.Service', $service);

        $this->assertTrue($server->hasService('test.Service'));
        $this->assertFalse($server->hasService('nonexistent.Service'));
    }

    public function testGrpcServerGetServices(): void
    {
        $server = new GrpcServer();

        $service = new class implements ServiceInterface {
            public function getServiceName(): string
            {
                return 'test.Service';
            }

            public function getMethods(): array
            {
                return [];
            }
        };

        $server->registerService('test.Service', $service);

        $services = $server->getServices();

        $this->assertArrayHasKey('test.Service', $services);
    }

    // ========================================================================
    // GrpcClient Tests
    // ========================================================================

    public function testGrpcClientConfiguration(): void
    {
        $client = new GrpcClient([
            'host' => 'example.com',
            'port' => 50052,
            'timeout' => 60,
        ]);

        // Client should be created without errors
        $this->assertInstanceOf(GrpcClient::class, $client);
    }

    public function testGrpcClientForService(): void
    {
        $client = new GrpcClient();
        $serviceClient = $client->forService('test.Service');

        $this->assertEquals('test.Service', $serviceClient->getServiceName());
    }

    // ========================================================================
    // MethodType Tests
    // ========================================================================

    public function testMethodTypes(): void
    {
        $this->assertEquals('unary', MethodType::UNARY->value);
        $this->assertEquals('server_streaming', MethodType::SERVER_STREAMING->value);
        $this->assertEquals('client_streaming', MethodType::CLIENT_STREAMING->value);
        $this->assertEquals('bidi_streaming', MethodType::BIDI_STREAMING->value);
    }
}
