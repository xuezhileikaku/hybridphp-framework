<?php

declare(strict_types=1);

namespace HybridPHP\Core\Server\Http2;

/**
 * HPACK Header Compression Encoder for HTTP/2
 * 
 * Implements RFC 7541 HPACK header compression with:
 * - Static table lookup (Appendix A)
 * - Dynamic table management
 * - Huffman encoding (Appendix B)
 * - Integer encoding with prefix
 */
class HpackEncoder
{
    /**
     * Static table as defined in RFC 7541 Appendix A
     */
    private const STATIC_TABLE = [
        1 => [':authority', ''],
        2 => [':method', 'GET'],
        3 => [':method', 'POST'],
        4 => [':path', '/'],
        5 => [':path', '/index.html'],
        6 => [':scheme', 'http'],
        7 => [':scheme', 'https'],
        8 => [':status', '200'],
        9 => [':status', '204'],
        10 => [':status', '206'],
        11 => [':status', '304'],
        12 => [':status', '400'],
        13 => [':status', '404'],
        14 => [':status', '500'],
        15 => ['accept-charset', ''],
        16 => ['accept-encoding', 'gzip, deflate'],
        17 => ['accept-language', ''],
        18 => ['accept-ranges', ''],
        19 => ['accept', ''],
        20 => ['access-control-allow-origin', ''],
        21 => ['age', ''],
        22 => ['allow', ''],
        23 => ['authorization', ''],
        24 => ['cache-control', ''],
        25 => ['content-disposition', ''],
        26 => ['content-encoding', ''],
        27 => ['content-language', ''],
        28 => ['content-length', ''],
        29 => ['content-location', ''],
        30 => ['content-range', ''],
        31 => ['content-type', ''],
        32 => ['cookie', ''],
        33 => ['date', ''],
        34 => ['etag', ''],
        35 => ['expect', ''],
        36 => ['expires', ''],
        37 => ['from', ''],
        38 => ['host', ''],
        39 => ['if-match', ''],
        40 => ['if-modified-since', ''],
        41 => ['if-none-match', ''],
        42 => ['if-range', ''],
        43 => ['if-unmodified-since', ''],
        44 => ['last-modified', ''],
        45 => ['link', ''],
        46 => ['location', ''],
        47 => ['max-forwards', ''],
        48 => ['proxy-authenticate', ''],
        49 => ['proxy-authorization', ''],
        50 => ['range', ''],
        51 => ['referer', ''],
        52 => ['refresh', ''],
        53 => ['retry-after', ''],
        54 => ['server', ''],
        55 => ['set-cookie', ''],
        56 => ['strict-transport-security', ''],
        57 => ['transfer-encoding', ''],
        58 => ['user-agent', ''],
        59 => ['vary', ''],
        60 => ['via', ''],
        61 => ['www-authenticate', ''],
    ];

    private const STATIC_TABLE_SIZE = 61;

    /**
     * Reverse lookup for static table (name => [index, ...])
     */
    private static ?array $staticTableByName = null;

    private array $dynamicTable = [];
    private int $dynamicTableSize = 0;
    private int $maxDynamicTableSize;
    private bool $useHuffman;
    private bool $indexingEnabled;

    public function __construct(
        int $maxDynamicTableSize = 4096,
        bool $useHuffman = true,
        bool $indexingEnabled = true
    ) {
        $this->maxDynamicTableSize = $maxDynamicTableSize;
        $this->useHuffman = $useHuffman;
        $this->indexingEnabled = $indexingEnabled;
        
        if (self::$staticTableByName === null) {
            self::buildStaticTableIndex();
        }
    }

    /**
     * Build reverse lookup index for static table
     */
    private static function buildStaticTableIndex(): void
    {
        self::$staticTableByName = [];
        foreach (self::STATIC_TABLE as $index => [$name, $value]) {
            if (!isset(self::$staticTableByName[$name])) {
                self::$staticTableByName[$name] = [];
            }
            self::$staticTableByName[$name][] = ['index' => $index, 'value' => $value];
        }
    }

    /**
     * Encode headers using HPACK
     * 
     * @param array<string, string|array> $headers Headers to encode
     * @return string HPACK-encoded data
     */
    public function encode(array $headers): string
    {
        $encoded = '';
        
        foreach ($headers as $name => $value) {
            // Handle array values (multiple headers with same name)
            if (is_array($value)) {
                foreach ($value as $v) {
                    $encoded .= $this->encodeHeader(strtolower($name), (string) $v);
                }
            } else {
                $encoded .= $this->encodeHeader(strtolower($name), (string) $value);
            }
        }
        
        return $encoded;
    }

    /**
     * Encode a single header
     */
    private function encodeHeader(string $name, string $value): string
    {
        // Try to find exact match in tables
        $exactMatch = $this->findExactMatch($name, $value);
        if ($exactMatch !== null) {
            // Indexed Header Field (Section 6.1)
            return $this->encodeInteger($exactMatch, 7, 0x80);
        }

        // Try to find name match
        $nameMatch = $this->findNameMatch($name);

        if ($this->indexingEnabled && !$this->isSensitiveHeader($name)) {
            // Literal Header Field with Incremental Indexing (Section 6.2.1)
            if ($nameMatch !== null) {
                $result = $this->encodeInteger($nameMatch, 6, 0x40);
            } else {
                $result = chr(0x40);
                $result .= $this->encodeString($name);
            }
            $result .= $this->encodeString($value);
            
            // Add to dynamic table
            $this->addToDynamicTable($name, $value);
            
            return $result;
        }

        if ($this->isSensitiveHeader($name)) {
            // Literal Header Field Never Indexed (Section 6.2.3)
            if ($nameMatch !== null) {
                $result = $this->encodeInteger($nameMatch, 4, 0x10);
            } else {
                $result = chr(0x10);
                $result .= $this->encodeString($name);
            }
            $result .= $this->encodeString($value);
            
            return $result;
        }

        // Literal Header Field without Indexing (Section 6.2.2)
        if ($nameMatch !== null) {
            $result = $this->encodeInteger($nameMatch, 4, 0x00);
        } else {
            $result = chr(0x00);
            $result .= $this->encodeString($name);
        }
        $result .= $this->encodeString($value);
        
        return $result;
    }

    /**
     * Find exact match (name + value) in static and dynamic tables
     */
    private function findExactMatch(string $name, string $value): ?int
    {
        // Check static table
        if (isset(self::$staticTableByName[$name])) {
            foreach (self::$staticTableByName[$name] as $entry) {
                if ($entry['value'] === $value) {
                    return $entry['index'];
                }
            }
        }

        // Check dynamic table
        foreach ($this->dynamicTable as $index => [$tableName, $tableValue]) {
            if ($tableName === $name && $tableValue === $value) {
                return self::STATIC_TABLE_SIZE + $index + 1;
            }
        }

        return null;
    }

    /**
     * Find name match in static and dynamic tables
     */
    private function findNameMatch(string $name): ?int
    {
        // Check static table first
        if (isset(self::$staticTableByName[$name])) {
            return self::$staticTableByName[$name][0]['index'];
        }

        // Check dynamic table
        foreach ($this->dynamicTable as $index => [$tableName, ]) {
            if ($tableName === $name) {
                return self::STATIC_TABLE_SIZE + $index + 1;
            }
        }

        return null;
    }

    /**
     * Check if header is sensitive and should never be indexed
     */
    private function isSensitiveHeader(string $name): bool
    {
        return in_array($name, [
            'authorization',
            'cookie',
            'set-cookie',
            'proxy-authorization',
        ], true);
    }

    /**
     * Encode integer with prefix
     */
    private function encodeInteger(int $value, int $prefixBits, int $prefix): string
    {
        $maxPrefix = (1 << $prefixBits) - 1;
        
        if ($value < $maxPrefix) {
            return chr($prefix | $value);
        }
        
        $result = chr($prefix | $maxPrefix);
        $value -= $maxPrefix;
        
        while ($value >= 128) {
            $result .= chr(($value & 0x7F) | 0x80);
            $value >>= 7;
        }
        
        $result .= chr($value);
        
        return $result;
    }

    /**
     * Encode string (with optional Huffman encoding)
     */
    private function encodeString(string $value): string
    {
        if ($this->useHuffman && HpackHuffman::shouldEncode($value)) {
            $encoded = HpackHuffman::encode($value);
            return $this->encodeInteger(strlen($encoded), 7, 0x80) . $encoded;
        }
        
        return $this->encodeInteger(strlen($value), 7, 0x00) . $value;
    }

    /**
     * Add entry to dynamic table
     */
    private function addToDynamicTable(string $name, string $value): void
    {
        $entrySize = strlen($name) + strlen($value) + 32; // 32 bytes overhead per RFC 7541
        
        // Evict entries if necessary
        while ($this->dynamicTableSize + $entrySize > $this->maxDynamicTableSize && !empty($this->dynamicTable)) {
            $evicted = array_pop($this->dynamicTable);
            $this->dynamicTableSize -= strlen($evicted[0]) + strlen($evicted[1]) + 32;
        }
        
        // Add new entry at the beginning
        if ($entrySize <= $this->maxDynamicTableSize) {
            array_unshift($this->dynamicTable, [$name, $value]);
            $this->dynamicTableSize += $entrySize;
        }
    }

    /**
     * Encode dynamic table size update
     */
    public function encodeDynamicTableSizeUpdate(int $newSize): string
    {
        $this->setMaxDynamicTableSize($newSize);
        return $this->encodeInteger($newSize, 5, 0x20);
    }

    /**
     * Get dynamic table size
     */
    public function getDynamicTableSize(): int
    {
        return $this->dynamicTableSize;
    }

    /**
     * Get dynamic table entries
     */
    public function getDynamicTable(): array
    {
        return $this->dynamicTable;
    }

    /**
     * Set maximum dynamic table size
     */
    public function setMaxDynamicTableSize(int $size): void
    {
        $this->maxDynamicTableSize = $size;
        
        // Evict entries if necessary
        while ($this->dynamicTableSize > $this->maxDynamicTableSize && !empty($this->dynamicTable)) {
            $evicted = array_pop($this->dynamicTable);
            $this->dynamicTableSize -= strlen($evicted[0]) + strlen($evicted[1]) + 32;
        }
    }

    /**
     * Get maximum dynamic table size
     */
    public function getMaxDynamicTableSize(): int
    {
        return $this->maxDynamicTableSize;
    }

    /**
     * Clear dynamic table
     */
    public function clearDynamicTable(): void
    {
        $this->dynamicTable = [];
        $this->dynamicTableSize = 0;
    }

    /**
     * Enable or disable Huffman encoding
     */
    public function setUseHuffman(bool $useHuffman): void
    {
        $this->useHuffman = $useHuffman;
    }

    /**
     * Check if Huffman encoding is enabled
     */
    public function isHuffmanEnabled(): bool
    {
        return $this->useHuffman;
    }

    /**
     * Enable or disable indexing
     */
    public function setIndexingEnabled(bool $enabled): void
    {
        $this->indexingEnabled = $enabled;
    }

    /**
     * Check if indexing is enabled
     */
    public function isIndexingEnabled(): bool
    {
        return $this->indexingEnabled;
    }

    /**
     * Get static table
     */
    public static function getStaticTable(): array
    {
        return self::STATIC_TABLE;
    }
}
