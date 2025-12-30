<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use HybridPHP\Core\Tracing\Tracer;
use HybridPHP\Core\Tracing\Span;
use HybridPHP\Core\Tracing\SpanContext;
use HybridPHP\Core\Tracing\SpanStatus;
use HybridPHP\Core\Tracing\SpanKind;
use HybridPHP\Core\Tracing\Exporter\ConsoleExporter;
use HybridPHP\Core\Tracing\Propagation\W3CTraceContextPropagator;
use HybridPHP\Core\Tracing\Propagation\B3Propagator;
use HybridPHP\Core\Tracing\Propagation\JaegerPropagator;
use HybridPHP\Core\Tracing\Propagation\CompositePropagator;

/**
 * Distributed Tracing unit tests
 */
class TracingTest extends TestCase
{
    private Tracer $tracer;

    protected function setUp(): void
    {
        $this->tracer = new Tracer(
            'test-service',
            new ConsoleExporter(false)
        );
    }

    protected function tearDown(): void
    {
        $this->tracer->shutdown();
    }

    // SpanContext Tests
    public function testSpanContextCreate(): void
    {
        $context = SpanContext::create();

        $this->assertNotEmpty($context->getTraceId());
        $this->assertNotEmpty($context->getSpanId());
        $this->assertEquals(32, strlen($context->getTraceId()));
        $this->assertEquals(16, strlen($context->getSpanId()));
        $this->assertTrue($context->isValid());
        $this->assertTrue($context->isSampled());
    }

    public function testSpanContextInvalid(): void
    {
        $context = SpanContext::createInvalid();

        $this->assertFalse($context->isValid());
        $this->assertFalse($context->isSampled());
    }

    public function testSpanContextWithSpanId(): void
    {
        $context = SpanContext::create();
        $newSpanId = SpanContext::generateSpanId();
        
        $newContext = $context->withSpanId($newSpanId);

        $this->assertEquals($context->getTraceId(), $newContext->getTraceId());
        $this->assertEquals($newSpanId, $newContext->getSpanId());
        $this->assertNotEquals($context->getSpanId(), $newContext->getSpanId());
    }

    // Span Tests
    public function testSpanCreation(): void
    {
        $context = SpanContext::create();
        $span = new Span('test-operation', $context);

        $this->assertEquals('test-operation', $span->getOperationName());
        $this->assertEquals($context->getTraceId(), $span->getTraceId());
        $this->assertEquals($context->getSpanId(), $span->getSpanId());
        $this->assertFalse($span->hasEnded());
        $this->assertTrue($span->isRoot());
    }

    public function testSpanAttributes(): void
    {
        $context = SpanContext::create();
        $span = new Span('test-operation', $context);

        $span->setAttribute('key1', 'value1');
        $span->setAttributes([
            'key2' => 123,
            'key3' => true,
        ]);

        $attributes = $span->getAttributes();

        $this->assertEquals('value1', $attributes['key1']);
        $this->assertEquals(123, $attributes['key2']);
        $this->assertTrue($attributes['key3']);
    }

    public function testSpanEvents(): void
    {
        $context = SpanContext::create();
        $span = new Span('test-operation', $context);

        $span->addEvent('event1', ['attr' => 'value']);
        $span->addEvent('event2');

        $events = $span->getEvents();

        $this->assertCount(2, $events);
        $this->assertEquals('event1', $events[0]['name']);
        $this->assertEquals('value', $events[0]['attributes']['attr']);
        $this->assertEquals('event2', $events[1]['name']);
    }

    public function testSpanStatus(): void
    {
        $context = SpanContext::create();
        $span = new Span('test-operation', $context);

        $this->assertEquals(SpanStatus::UNSET, $span->getStatus());

        $span->setStatus(SpanStatus::OK);
        $this->assertEquals(SpanStatus::OK, $span->getStatus());

        // ERROR can override OK
        $span->setStatus(SpanStatus::ERROR, 'Something failed');
        $this->assertEquals(SpanStatus::ERROR, $span->getStatus());
    }

    public function testSpanRecordException(): void
    {
        $context = SpanContext::create();
        $span = new Span('test-operation', $context);

        $exception = new \RuntimeException('Test error', 500);
        $span->recordException($exception);

        $events = $span->getEvents();
        $this->assertCount(1, $events);
        $this->assertEquals('exception', $events[0]['name']);
        $this->assertEquals('RuntimeException', $events[0]['attributes']['exception.type']);
        $this->assertEquals('Test error', $events[0]['attributes']['exception.message']);
        $this->assertEquals(SpanStatus::ERROR, $span->getStatus());
    }

    public function testSpanEnd(): void
    {
        $context = SpanContext::create();
        $span = new Span('test-operation', $context);

        $this->assertFalse($span->hasEnded());
        $this->assertNull($span->getEndTime());
        $this->assertNull($span->getDuration());

        $span->end();

        $this->assertTrue($span->hasEnded());
        $this->assertNotNull($span->getEndTime());
        $this->assertNotNull($span->getDuration());
        $this->assertGreaterThanOrEqual(0, $span->getDuration());
    }

    public function testSpanToArray(): void
    {
        $context = SpanContext::create();
        $span = new Span('test-operation', $context, null, SpanKind::SERVER, ['key' => 'value']);
        $span->end();

        $array = $span->toArray();

        $this->assertArrayHasKey('trace_id', $array);
        $this->assertArrayHasKey('span_id', $array);
        $this->assertArrayHasKey('operation_name', $array);
        $this->assertArrayHasKey('kind', $array);
        $this->assertArrayHasKey('start_time', $array);
        $this->assertArrayHasKey('end_time', $array);
        $this->assertArrayHasKey('duration', $array);
        $this->assertArrayHasKey('attributes', $array);
        $this->assertEquals('test-operation', $array['operation_name']);
        $this->assertEquals('server', $array['kind']);
    }

    // Tracer Tests
    public function testTracerStartTrace(): void
    {
        $span = $this->tracer->startTrace('root-operation');

        $this->assertInstanceOf(Span::class, $span);
        $this->assertEquals('root-operation', $span->getOperationName());
        $this->assertTrue($span->isRoot());

        $span->end();
    }

    public function testTracerStartSpan(): void
    {
        $rootSpan = $this->tracer->startTrace('root');
        $childSpan = $this->tracer->startSpan('child');

        $this->assertEquals($rootSpan->getTraceId(), $childSpan->getTraceId());
        $this->assertNotEquals($rootSpan->getSpanId(), $childSpan->getSpanId());
        $this->assertEquals($rootSpan->getSpanId(), $childSpan->getParentSpanId());

        $childSpan->end();
        $rootSpan->end();
    }

    public function testTracerGetCurrentSpan(): void
    {
        $this->assertNull($this->tracer->getCurrentSpan());

        $span = $this->tracer->startTrace('test');
        $this->assertSame($span, $this->tracer->getCurrentSpan());

        $span->end();
    }

    public function testTracerContextPropagation(): void
    {
        $span = $this->tracer->startTrace('test');

        $headers = [];
        $this->tracer->inject($headers);

        $this->assertArrayHasKey('traceparent', $headers);
        $this->assertStringContainsString($span->getTraceId(), $headers['traceparent']);

        $span->end();
    }

    // W3C Trace Context Propagator Tests
    public function testW3CExtract(): void
    {
        $propagator = new W3CTraceContextPropagator();
        
        $headers = [
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01',
        ];

        $context = $propagator->extract($headers);

        $this->assertNotNull($context);
        $this->assertEquals('0af7651916cd43dd8448eb211c80319c', $context->getTraceId());
        $this->assertEquals('b7ad6b7169203331', $context->getSpanId());
        $this->assertTrue($context->isSampled());
        $this->assertTrue($context->isRemote());
    }

    public function testW3CInject(): void
    {
        $propagator = new W3CTraceContextPropagator();
        $context = SpanContext::create('0af7651916cd43dd8448eb211c80319c', 'b7ad6b7169203331');

        $headers = [];
        $propagator->inject($context, $headers);

        $this->assertArrayHasKey('traceparent', $headers);
        $this->assertStringStartsWith('00-', $headers['traceparent']);
        $this->assertStringContainsString('0af7651916cd43dd8448eb211c80319c', $headers['traceparent']);
    }

    public function testW3CInvalidTraceparent(): void
    {
        $propagator = new W3CTraceContextPropagator();

        // Invalid format
        $this->assertNull($propagator->extract(['traceparent' => 'invalid']));

        // All zeros trace ID
        $this->assertNull($propagator->extract([
            'traceparent' => '00-00000000000000000000000000000000-b7ad6b7169203331-01'
        ]));

        // All zeros span ID
        $this->assertNull($propagator->extract([
            'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-0000000000000000-01'
        ]));
    }

    // B3 Propagator Tests
    public function testB3MultiHeaderExtract(): void
    {
        $propagator = new B3Propagator();

        $headers = [
            'x-b3-traceid' => '0af7651916cd43dd8448eb211c80319c',
            'x-b3-spanid' => 'b7ad6b7169203331',
            'x-b3-sampled' => '1',
        ];

        $context = $propagator->extract($headers);

        $this->assertNotNull($context);
        $this->assertEquals('0af7651916cd43dd8448eb211c80319c', $context->getTraceId());
        $this->assertEquals('b7ad6b7169203331', $context->getSpanId());
        $this->assertTrue($context->isSampled());
    }

    public function testB3SingleHeaderExtract(): void
    {
        $propagator = new B3Propagator();

        $headers = [
            'b3' => '0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-1',
        ];

        $context = $propagator->extract($headers);

        $this->assertNotNull($context);
        $this->assertTrue($context->isSampled());
    }

    public function testB3Inject(): void
    {
        $propagator = new B3Propagator();
        $context = SpanContext::create();

        $headers = [];
        $propagator->inject($context, $headers);

        $this->assertArrayHasKey('x-b3-traceid', $headers);
        $this->assertArrayHasKey('x-b3-spanid', $headers);
        $this->assertArrayHasKey('x-b3-sampled', $headers);
    }

    // Jaeger Propagator Tests
    public function testJaegerExtract(): void
    {
        $propagator = new JaegerPropagator();

        $headers = [
            'uber-trace-id' => '0af7651916cd43dd8448eb211c80319c:b7ad6b7169203331:0:1',
        ];

        $context = $propagator->extract($headers);

        $this->assertNotNull($context);
        $this->assertEquals('0af7651916cd43dd8448eb211c80319c', $context->getTraceId());
        $this->assertTrue($context->isSampled());
    }

    public function testJaegerInject(): void
    {
        $propagator = new JaegerPropagator();
        $context = SpanContext::create();

        $headers = [];
        $propagator->inject($context, $headers);

        $this->assertArrayHasKey('uber-trace-id', $headers);
        $this->assertStringContainsString(':', $headers['uber-trace-id']);
    }

    // Composite Propagator Tests
    public function testCompositePropagator(): void
    {
        $propagator = CompositePropagator::createDefault();

        // Should extract from any supported format
        $w3cHeaders = ['traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'];
        $context1 = $propagator->extract($w3cHeaders);
        $this->assertNotNull($context1);

        $b3Headers = ['x-b3-traceid' => '0af7651916cd43dd8448eb211c80319c', 'x-b3-spanid' => 'b7ad6b7169203331'];
        $context2 = $propagator->extract($b3Headers);
        $this->assertNotNull($context2);

        // Should inject all formats
        $context = SpanContext::create();
        $headers = [];
        $propagator->inject($context, $headers);

        $this->assertArrayHasKey('traceparent', $headers);
        $this->assertArrayHasKey('x-b3-traceid', $headers);
        $this->assertArrayHasKey('uber-trace-id', $headers);
    }

    // Sampling Tests
    public function testSamplingRate(): void
    {
        // 0% sampling
        $tracer = new Tracer('test', new ConsoleExporter(false), null, null, ['sampling_rate' => 0.0]);
        $span = $tracer->startTrace('test');
        $this->assertFalse($span->getContext()->isSampled());
        $span->end();
        $tracer->shutdown();

        // 100% sampling
        $tracer = new Tracer('test', new ConsoleExporter(false), null, null, ['sampling_rate' => 1.0]);
        $span = $tracer->startTrace('test');
        $this->assertTrue($span->getContext()->isSampled());
        $span->end();
        $tracer->shutdown();
    }

    // SpanKind Tests
    public function testSpanKind(): void
    {
        $this->assertEquals(0, SpanKind::INTERNAL->getCode());
        $this->assertEquals(1, SpanKind::SERVER->getCode());
        $this->assertEquals(2, SpanKind::CLIENT->getCode());
        $this->assertEquals(3, SpanKind::PRODUCER->getCode());
        $this->assertEquals(4, SpanKind::CONSUMER->getCode());

        $this->assertEquals(SpanKind::SERVER, SpanKind::fromCode(1));
        $this->assertEquals(SpanKind::INTERNAL, SpanKind::fromCode(99));
    }

    // SpanStatus Tests
    public function testSpanStatusCodes(): void
    {
        $this->assertEquals(0, SpanStatus::UNSET->getCode());
        $this->assertEquals(1, SpanStatus::OK->getCode());
        $this->assertEquals(2, SpanStatus::ERROR->getCode());

        $this->assertEquals(SpanStatus::OK, SpanStatus::fromCode(1));
        $this->assertEquals(SpanStatus::UNSET, SpanStatus::fromCode(99));
    }
}
