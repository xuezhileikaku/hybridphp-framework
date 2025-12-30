<?php

declare(strict_types=1);

namespace HybridPHP\Core\Server\Http2;

/**
 * HPACK Header Decoder for HTTP/2
 * 
 * Implements RFC 7541 HPACK header decompression.
 */
class HpackDecoder
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

    private array $dynamicTable = [];
    private int $dynamicTableSize = 0;
    private int $maxDynamicTableSize;
    private int $maxDynamicTableSizeLimit;

    public function __construct(int $maxDynamicTableSize = 4096)
    {
        $this->maxDynamicTableSize = $maxDynamicTableSize;
        $this->maxDynamicTableSizeLimit = $maxDynamicTableSize;
    }

    /**
     * Decode HPACK-encoded headers
     * 
     * @param string $data HPACK-encoded data
     * @return array<string, string> Decoded headers
     */
    public function decode(string $data): array
    {
        $headers = [];
        $offset = 0;
        $length = strlen($data);

        while ($offset < $length) {
            $byte = ord($data[$offset]);

            if ($byte & 0x80) {
                // Indexed Header Field (Section 6.1)
                [$name, $value, $offset] = $this->decodeIndexedHeader($data, $offset);
            } elseif ($byte & 0x40) {
                // Literal Header Field with Incremental Indexing (Section 6.2.1)
                [$name, $value, $offset] = $this->decodeLiteralWithIndexing($data, $offset);
            } elseif ($byte & 0x20) {
                // Dynamic Table Size Update (Section 6.3)
                $offset = $this->decodeDynamicTableSizeUpdate($data, $offset);
                continue;
            } elseif ($byte & 0x10) {
                // Literal Header Field Never Indexed (Section 6.2.3)
                [$name, $value, $offset] = $this->decodeLiteralNeverIndexed($data, $offset);
            } else {
                // Literal Header Field without Indexing (Section 6.2.2)
                [$name, $value, $offset] = $this->decodeLiteralWithoutIndexing($data, $offset);
            }

            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * Decode indexed header field
     */
    private function decodeIndexedHeader(string $data, int $offset): array
    {
        [$index, $offset] = $this->decodeInteger($data, $offset, 7);

        if ($index === 0) {
            throw HpackException::invalidIndex(0);
        }

        [$name, $value] = $this->getFromTable($index);

        return [$name, $value, $offset];
    }

    /**
     * Decode literal header with incremental indexing
     */
    private function decodeLiteralWithIndexing(string $data, int $offset): array
    {
        [$index, $offset] = $this->decodeInteger($data, $offset, 6);

        if ($index > 0) {
            [$name, ] = $this->getFromTable($index);
        } else {
            [$name, $offset] = $this->decodeString($data, $offset);
        }

        [$value, $offset] = $this->decodeString($data, $offset);

        // Add to dynamic table
        $this->addToDynamicTable($name, $value);

        return [$name, $value, $offset];
    }

    /**
     * Decode literal header without indexing
     */
    private function decodeLiteralWithoutIndexing(string $data, int $offset): array
    {
        [$index, $offset] = $this->decodeInteger($data, $offset, 4);

        if ($index > 0) {
            [$name, ] = $this->getFromTable($index);
        } else {
            [$name, $offset] = $this->decodeString($data, $offset);
        }

        [$value, $offset] = $this->decodeString($data, $offset);

        return [$name, $value, $offset];
    }

    /**
     * Decode literal header never indexed
     */
    private function decodeLiteralNeverIndexed(string $data, int $offset): array
    {
        [$index, $offset] = $this->decodeInteger($data, $offset, 4);

        if ($index > 0) {
            [$name, ] = $this->getFromTable($index);
        } else {
            [$name, $offset] = $this->decodeString($data, $offset);
        }

        [$value, $offset] = $this->decodeString($data, $offset);

        return [$name, $value, $offset];
    }

    /**
     * Decode dynamic table size update
     */
    private function decodeDynamicTableSizeUpdate(string $data, int $offset): int
    {
        [$newSize, $offset] = $this->decodeInteger($data, $offset, 5);

        if ($newSize > $this->maxDynamicTableSizeLimit) {
            throw HpackException::tableSizeExceeded($newSize, $this->maxDynamicTableSizeLimit);
        }

        $this->setMaxDynamicTableSize($newSize);

        return $offset;
    }

    /**
     * Decode an integer with the given prefix bits
     */
    private function decodeInteger(string $data, int $offset, int $prefixBits): array
    {
        $maxPrefix = (1 << $prefixBits) - 1;
        $byte = ord($data[$offset]);
        $value = $byte & $maxPrefix;
        $offset++;

        if ($value < $maxPrefix) {
            return [$value, $offset];
        }

        $shift = 0;
        $length = strlen($data);

        do {
            if ($offset >= $length) {
                throw HpackException::invalidInteger('Unexpected end of data');
            }

            $byte = ord($data[$offset]);
            $offset++;
            $value += ($byte & 0x7F) << $shift;
            $shift += 7;

            if ($shift > 28) {
                throw HpackException::invalidInteger('Integer overflow');
            }
        } while ($byte & 0x80);

        return [$value, $offset];
    }

    /**
     * Decode a string (with optional Huffman decoding)
     */
    private function decodeString(string $data, int $offset): array
    {
        $byte = ord($data[$offset]);
        $huffman = (bool) ($byte & 0x80);

        [$length, $offset] = $this->decodeInteger($data, $offset, 7);

        if ($offset + $length > strlen($data)) {
            throw HpackException::decompressionFailed('String length exceeds data');
        }

        $value = substr($data, $offset, $length);
        $offset += $length;

        if ($huffman) {
            $value = HpackHuffman::decode($value);
        }

        return [$value, $offset];
    }

    /**
     * Get header from static or dynamic table
     */
    private function getFromTable(int $index): array
    {
        if ($index <= self::STATIC_TABLE_SIZE) {
            if (!isset(self::STATIC_TABLE[$index])) {
                throw HpackException::invalidIndex($index);
            }
            return self::STATIC_TABLE[$index];
        }

        $dynamicIndex = $index - self::STATIC_TABLE_SIZE - 1;

        if (!isset($this->dynamicTable[$dynamicIndex])) {
            throw HpackException::invalidIndex($index);
        }

        return $this->dynamicTable[$dynamicIndex];
    }

    /**
     * Add entry to dynamic table
     */
    private function addToDynamicTable(string $name, string $value): void
    {
        $entrySize = strlen($name) + strlen($value) + 32;

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
     * Set the maximum allowed dynamic table size limit
     */
    public function setMaxDynamicTableSizeLimit(int $limit): void
    {
        $this->maxDynamicTableSizeLimit = $limit;
        if ($this->maxDynamicTableSize > $limit) {
            $this->setMaxDynamicTableSize($limit);
        }
    }

    /**
     * Get current dynamic table size
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
     * Clear dynamic table
     */
    public function clearDynamicTable(): void
    {
        $this->dynamicTable = [];
        $this->dynamicTableSize = 0;
    }

    /**
     * Get static table
     */
    public static function getStaticTable(): array
    {
        return self::STATIC_TABLE;
    }
}
