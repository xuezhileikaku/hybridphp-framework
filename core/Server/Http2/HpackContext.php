<?php

declare(strict_types=1);

namespace HybridPHP\Core\Server\Http2;

/**
 * HPACK Compression Context for HTTP/2
 * 
 * Manages the HPACK encoder and decoder for a single HTTP/2 connection.
 * Each connection should have its own HpackContext to maintain separate
 * dynamic tables for encoding and decoding.
 * 
 * @see RFC 7541 - HPACK: Header Compression for HTTP/2
 */
class HpackContext
{
    private HpackEncoder $encoder;
    private HpackDecoder $decoder;
    private int $headerTableSize;
    private array $stats = [
        'headers_encoded' => 0,
        'headers_decoded' => 0,
        'bytes_before_compression' => 0,
        'bytes_after_compression' => 0,
        'compression_ratio' => 0.0,
    ];

    /**
     * Create a new HPACK context
     * 
     * @param int $headerTableSize Maximum size of the dynamic table (default 4096 per RFC 7541)
     * @param bool $useHuffman Whether to use Huffman encoding (default true)
     */
    public function __construct(int $headerTableSize = 4096, bool $useHuffman = true)
    {
        $this->headerTableSize = $headerTableSize;
        $this->encoder = new HpackEncoder($headerTableSize, $useHuffman);
        $this->decoder = new HpackDecoder($headerTableSize);
    }

    /**
     * Compress headers using HPACK
     * 
     * @param array<string, string|array> $headers Headers to compress
     * @return string HPACK-compressed data
     */
    public function compress(array $headers): string
    {
        // Calculate uncompressed size
        $uncompressedSize = 0;
        foreach ($headers as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $v) {
                    $uncompressedSize += strlen($name) + strlen((string) $v) + 4; // name: value\r\n
                }
            } else {
                $uncompressedSize += strlen($name) + strlen((string) $value) + 4;
            }
        }

        $compressed = $this->encoder->encode($headers);
        $compressedSize = strlen($compressed);

        // Update stats
        $this->stats['headers_encoded']++;
        $this->stats['bytes_before_compression'] += $uncompressedSize;
        $this->stats['bytes_after_compression'] += $compressedSize;
        $this->updateCompressionRatio();

        return $compressed;
    }

    /**
     * Decompress HPACK-encoded headers
     * 
     * @param string $data HPACK-compressed data
     * @return array<string, string> Decompressed headers
     */
    public function decompress(string $data): array
    {
        $headers = $this->decoder->decode($data);
        $this->stats['headers_decoded']++;
        return $headers;
    }

    /**
     * Update the header table size
     * 
     * @param int $size New maximum size
     * @return string HPACK-encoded size update (to be sent to peer)
     */
    public function updateTableSize(int $size): string
    {
        $this->headerTableSize = $size;
        $this->decoder->setMaxDynamicTableSize($size);
        return $this->encoder->encodeDynamicTableSizeUpdate($size);
    }

    /**
     * Set the maximum table size limit (from SETTINGS frame)
     */
    public function setTableSizeLimit(int $limit): void
    {
        $this->decoder->setMaxDynamicTableSizeLimit($limit);
        if ($this->headerTableSize > $limit) {
            $this->headerTableSize = $limit;
            $this->encoder->setMaxDynamicTableSize($limit);
        }
    }

    /**
     * Get the encoder instance
     */
    public function getEncoder(): HpackEncoder
    {
        return $this->encoder;
    }

    /**
     * Get the decoder instance
     */
    public function getDecoder(): HpackDecoder
    {
        return $this->decoder;
    }

    /**
     * Get current header table size
     */
    public function getHeaderTableSize(): int
    {
        return $this->headerTableSize;
    }

    /**
     * Get encoder dynamic table size
     */
    public function getEncoderTableSize(): int
    {
        return $this->encoder->getDynamicTableSize();
    }

    /**
     * Get decoder dynamic table size
     */
    public function getDecoderTableSize(): int
    {
        return $this->decoder->getDynamicTableSize();
    }

    /**
     * Clear both dynamic tables
     */
    public function clearTables(): void
    {
        $this->encoder->clearDynamicTable();
        $this->decoder->clearDynamicTable();
    }

    /**
     * Get compression statistics
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Reset statistics
     */
    public function resetStats(): void
    {
        $this->stats = [
            'headers_encoded' => 0,
            'headers_decoded' => 0,
            'bytes_before_compression' => 0,
            'bytes_after_compression' => 0,
            'compression_ratio' => 0.0,
        ];
    }

    /**
     * Update compression ratio
     */
    private function updateCompressionRatio(): void
    {
        if ($this->stats['bytes_before_compression'] > 0) {
            $this->stats['compression_ratio'] = 1 - (
                $this->stats['bytes_after_compression'] / $this->stats['bytes_before_compression']
            );
        }
    }

    /**
     * Enable or disable Huffman encoding
     */
    public function setUseHuffman(bool $useHuffman): void
    {
        $this->encoder->setUseHuffman($useHuffman);
    }

    /**
     * Check if Huffman encoding is enabled
     */
    public function isHuffmanEnabled(): bool
    {
        return $this->encoder->isHuffmanEnabled();
    }

    /**
     * Get debug information about the context
     */
    public function getDebugInfo(): array
    {
        return [
            'header_table_size' => $this->headerTableSize,
            'encoder' => [
                'dynamic_table_size' => $this->encoder->getDynamicTableSize(),
                'dynamic_table_entries' => count($this->encoder->getDynamicTable()),
                'huffman_enabled' => $this->encoder->isHuffmanEnabled(),
                'indexing_enabled' => $this->encoder->isIndexingEnabled(),
            ],
            'decoder' => [
                'dynamic_table_size' => $this->decoder->getDynamicTableSize(),
                'dynamic_table_entries' => count($this->decoder->getDynamicTable()),
            ],
            'stats' => $this->stats,
        ];
    }
}
