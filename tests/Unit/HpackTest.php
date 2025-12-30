<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use HybridPHP\Core\Server\Http2\HpackEncoder;
use HybridPHP\Core\Server\Http2\HpackDecoder;
use HybridPHP\Core\Server\Http2\HpackContext;
use HybridPHP\Core\Server\Http2\HpackHuffman;
use HybridPHP\Core\Server\Http2\HpackException;

/**
 * HPACK Header Compression Tests
 */
class HpackTest extends TestCase
{
    // ==================== HpackEncoder Tests ====================

    public function testEncodeIndexedHeader(): void
    {
        $encoder = new HpackEncoder(4096, false); // Disable Huffman for predictable output
        
        // :method GET is index 2 in static table
        $encoded = $encoder->encode([':method' => 'GET']);
        
        // Should be indexed header field (0x82 = 0x80 | 2)
        $this->assertEquals(chr(0x82), $encoded);
    }

    public function testEncodeStaticTableNameMatch(): void
    {
        $encoder = new HpackEncoder(4096, false);
        
        // :status is in static table but value 201 is not
        $encoded = $encoder->encode([':status' => '201']);
        
        // First byte should indicate literal with indexing (0x40 | index)
        $firstByte = ord($encoded[0]);
        $this->assertTrue(($firstByte & 0x40) !== 0);
    }

    public function testEncodeLiteralNewName(): void
    {
        $encoder = new HpackEncoder(4096, false);
        
        // Custom header not in static table
        $encoded = $encoder->encode(['x-custom-header' => 'custom-value']);
        
        // First byte should be 0x40 (literal with indexing, new name)
        $this->assertEquals(0x40, ord($encoded[0]));
    }

    public function testDynamicTableIndexing(): void
    {
        $encoder = new HpackEncoder(4096, false);
        
        // First encode adds to dynamic table
        $encoder->encode(['x-custom' => 'value1']);
        
        // Second encode should find it in dynamic table
        $encoded = $encoder->encode(['x-custom' => 'value1']);
        
        // Should be indexed (first bit set)
        $this->assertTrue((ord($encoded[0]) & 0x80) !== 0);
    }

    public function testSensitiveHeadersNeverIndexed(): void
    {
        $encoder = new HpackEncoder(4096, false);
        
        // Authorization should use never indexed representation
        $encoded = $encoder->encode(['authorization' => 'Bearer token']);
        
        // First byte should indicate never indexed (0x10)
        $this->assertTrue((ord($encoded[0]) & 0x10) !== 0);
    }

    public function testDynamicTableEviction(): void
    {
        // Small table size to force eviction
        $encoder = new HpackEncoder(100, false);
        
        // Add entries until eviction occurs
        $encoder->encode(['header1' => 'value1']);
        $encoder->encode(['header2' => 'value2']);
        $encoder->encode(['header3' => 'value3']);
        
        // Table should not exceed max size
        $this->assertLessThanOrEqual(100, $encoder->getDynamicTableSize());
    }

    public function testClearDynamicTable(): void
    {
        $encoder = new HpackEncoder(4096, false);
        
        $encoder->encode(['x-test' => 'value']);
        $this->assertGreaterThan(0, $encoder->getDynamicTableSize());
        
        $encoder->clearDynamicTable();
        $this->assertEquals(0, $encoder->getDynamicTableSize());
    }

    // ==================== HpackDecoder Tests ====================

    public function testDecodeIndexedHeader(): void
    {
        $decoder = new HpackDecoder(4096);
        
        // 0x82 = indexed header field, index 2 (:method GET)
        $headers = $decoder->decode(chr(0x82));
        
        $this->assertEquals('GET', $headers[':method']);
    }

    public function testDecodeLiteralWithIndexing(): void
    {
        $decoder = new HpackDecoder(4096);
        
        // Literal with indexing, new name
        // 0x40 + name length + name + value length + value
        $data = chr(0x40) . chr(6) . 'x-test' . chr(5) . 'value';
        
        $headers = $decoder->decode($data);
        
        $this->assertEquals('value', $headers['x-test']);
    }

    public function testDecodeLiteralWithoutIndexing(): void
    {
        $decoder = new HpackDecoder(4096);
        
        // Literal without indexing, new name
        $data = chr(0x00) . chr(6) . 'x-test' . chr(5) . 'value';
        
        $headers = $decoder->decode($data);
        
        $this->assertEquals('value', $headers['x-test']);
        // Should not be added to dynamic table
        $this->assertEquals(0, $decoder->getDynamicTableSize());
    }

    public function testDecodeLiteralNeverIndexed(): void
    {
        $decoder = new HpackDecoder(4096);
        
        // Literal never indexed, new name
        $data = chr(0x10) . chr(6) . 'x-test' . chr(5) . 'value';
        
        $headers = $decoder->decode($data);
        
        $this->assertEquals('value', $headers['x-test']);
    }

    public function testDecodeMultipleHeaders(): void
    {
        $decoder = new HpackDecoder(4096);
        
        // :method GET (0x82) + :path / (0x84)
        $data = chr(0x82) . chr(0x84);
        
        $headers = $decoder->decode($data);
        
        $this->assertEquals('GET', $headers[':method']);
        $this->assertEquals('/', $headers[':path']);
    }

    public function testDecodeInvalidIndex(): void
    {
        $decoder = new HpackDecoder(4096);
        
        $this->expectException(HpackException::class);
        
        // Index 0 is invalid
        $decoder->decode(chr(0x80));
    }

    // ==================== HpackHuffman Tests ====================

    public function testHuffmanEncodeDecode(): void
    {
        $original = 'www.example.com';
        
        $encoded = HpackHuffman::encode($original);
        $decoded = HpackHuffman::decode($encoded);
        
        $this->assertEquals($original, $decoded);
    }

    public function testHuffmanEncodeDecodeVariousStrings(): void
    {
        $testStrings = [
            'application/json',
            'text/html; charset=utf-8',
            'GET',
            'POST',
            '/api/v1/users',
            'Mozilla/5.0',
            'gzip, deflate, br',
            '200',
            '404',
        ];
        
        foreach ($testStrings as $original) {
            $encoded = HpackHuffman::encode($original);
            $decoded = HpackHuffman::decode($encoded);
            
            $this->assertEquals($original, $decoded, "Failed for: {$original}");
        }
    }

    public function testHuffmanShouldEncode(): void
    {
        // Short strings may not benefit from Huffman
        $this->assertFalse(HpackHuffman::shouldEncode('a'));
        
        // Longer strings typically benefit
        $this->assertTrue(HpackHuffman::shouldEncode('application/json'));
    }

    public function testHuffmanEncodedLength(): void
    {
        $value = 'www.example.com';
        
        $encodedLength = HpackHuffman::encodedLength($value);
        $actualEncoded = HpackHuffman::encode($value);
        
        $this->assertEquals($encodedLength, strlen($actualEncoded));
    }

    // ==================== HpackContext Tests ====================

    public function testContextCompressDecompress(): void
    {
        $context = new HpackContext(4096, false);
        
        $headers = [
            ':method' => 'GET',
            ':path' => '/api/users',
            ':scheme' => 'https',
            ':authority' => 'example.com',
            'accept' => 'application/json',
        ];
        
        $compressed = $context->compress($headers);
        $decompressed = $context->decompress($compressed);
        
        foreach ($headers as $name => $value) {
            $this->assertEquals($value, $decompressed[$name]);
        }
    }

    public function testContextWithHuffman(): void
    {
        $contextWithHuffman = new HpackContext(4096, true);
        $contextWithoutHuffman = new HpackContext(4096, false);
        
        $headers = [
            'content-type' => 'application/json',
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        ];
        
        $withHuffman = $contextWithHuffman->compress($headers);
        $withoutHuffman = $contextWithoutHuffman->compress($headers);
        
        // Huffman should produce smaller output for these headers
        $this->assertLessThan(strlen($withoutHuffman), strlen($withHuffman));
    }

    public function testContextCompressionStats(): void
    {
        $context = new HpackContext(4096, true);
        
        $headers = [
            ':method' => 'GET',
            ':path' => '/api/users',
            'accept' => 'application/json',
        ];
        
        $context->compress($headers);
        
        $stats = $context->getStats();
        
        $this->assertEquals(1, $stats['headers_encoded']);
        $this->assertGreaterThan(0, $stats['bytes_before_compression']);
        $this->assertGreaterThan(0, $stats['bytes_after_compression']);
        $this->assertGreaterThan(0, $stats['compression_ratio']);
    }

    public function testContextTableSizeUpdate(): void
    {
        $context = new HpackContext(4096, false);
        
        $sizeUpdate = $context->updateTableSize(8192);
        
        $this->assertEquals(8192, $context->getHeaderTableSize());
        $this->assertNotEmpty($sizeUpdate);
    }

    public function testContextClearTables(): void
    {
        $context = new HpackContext(4096, false);
        
        $context->compress(['x-test' => 'value']);
        $this->assertGreaterThan(0, $context->getEncoderTableSize());
        
        $context->clearTables();
        
        $this->assertEquals(0, $context->getEncoderTableSize());
        $this->assertEquals(0, $context->getDecoderTableSize());
    }

    public function testContextDebugInfo(): void
    {
        $context = new HpackContext(4096, true);
        
        $debug = $context->getDebugInfo();
        
        $this->assertArrayHasKey('header_table_size', $debug);
        $this->assertArrayHasKey('encoder', $debug);
        $this->assertArrayHasKey('decoder', $debug);
        $this->assertArrayHasKey('stats', $debug);
        $this->assertTrue($debug['encoder']['huffman_enabled']);
    }

    // ==================== Round-trip Tests ====================

    public function testFullRoundTrip(): void
    {
        $encoder = new HpackEncoder(4096, true);
        $decoder = new HpackDecoder(4096);
        
        $headers = [
            ':method' => 'POST',
            ':path' => '/api/v1/users',
            ':scheme' => 'https',
            ':authority' => 'api.example.com',
            'content-type' => 'application/json',
            'accept' => 'application/json',
            'authorization' => 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9',
            'x-request-id' => '550e8400-e29b-41d4-a716-446655440000',
        ];
        
        $encoded = $encoder->encode($headers);
        $decoded = $decoder->decode($encoded);
        
        foreach ($headers as $name => $value) {
            $this->assertEquals($value, $decoded[$name], "Mismatch for header: {$name}");
        }
    }

    public function testMultipleRequestsWithDynamicTable(): void
    {
        $encoder = new HpackEncoder(4096, false);
        $decoder = new HpackDecoder(4096);
        
        // First request
        $headers1 = [
            ':method' => 'GET',
            ':path' => '/api/users',
            'x-api-key' => 'secret123',
        ];
        
        $encoded1 = $encoder->encode($headers1);
        $decoded1 = $decoder->decode($encoded1);
        
        // Second request with same custom header
        $headers2 = [
            ':method' => 'GET',
            ':path' => '/api/posts',
            'x-api-key' => 'secret123', // Same as before
        ];
        
        $encoded2 = $encoder->encode($headers2);
        $decoded2 = $decoder->decode($encoded2);
        
        // Second encoding should be smaller due to dynamic table
        $this->assertLessThan(strlen($encoded1), strlen($encoded2));
        
        // Both should decode correctly
        $this->assertEquals('secret123', $decoded1['x-api-key']);
        $this->assertEquals('secret123', $decoded2['x-api-key']);
    }

    // ==================== Static Table Tests ====================

    public function testStaticTableEntries(): void
    {
        $staticTable = HpackEncoder::getStaticTable();
        
        // Verify some well-known entries
        $this->assertEquals([':authority', ''], $staticTable[1]);
        $this->assertEquals([':method', 'GET'], $staticTable[2]);
        $this->assertEquals([':method', 'POST'], $staticTable[3]);
        $this->assertEquals([':path', '/'], $staticTable[4]);
        $this->assertEquals([':scheme', 'https'], $staticTable[7]);
        $this->assertEquals([':status', '200'], $staticTable[8]);
        
        // Table should have 61 entries
        $this->assertCount(61, $staticTable);
    }
}
