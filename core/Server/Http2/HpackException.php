<?php

declare(strict_types=1);

namespace HybridPHP\Core\Server\Http2;

/**
 * HPACK Exception
 * 
 * Thrown when HPACK encoding/decoding errors occur.
 */
class HpackException extends \RuntimeException
{
    public const INVALID_INDEX = 1;
    public const INVALID_HUFFMAN = 2;
    public const INVALID_INTEGER = 3;
    public const TABLE_SIZE_EXCEEDED = 4;
    public const DECOMPRESSION_FAILED = 5;

    public static function invalidIndex(int $index): self
    {
        return new self("Invalid HPACK index: {$index}", self::INVALID_INDEX);
    }

    public static function invalidHuffman(string $message = 'Invalid Huffman encoding'): self
    {
        return new self($message, self::INVALID_HUFFMAN);
    }

    public static function invalidInteger(string $message = 'Invalid integer encoding'): self
    {
        return new self($message, self::INVALID_INTEGER);
    }

    public static function tableSizeExceeded(int $size, int $max): self
    {
        return new self("Dynamic table size {$size} exceeds maximum {$max}", self::TABLE_SIZE_EXCEEDED);
    }

    public static function decompressionFailed(string $message): self
    {
        return new self("HPACK decompression failed: {$message}", self::DECOMPRESSION_FAILED);
    }
}
