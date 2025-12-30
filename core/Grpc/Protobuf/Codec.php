<?php

declare(strict_types=1);

namespace HybridPHP\Core\Grpc\Protobuf;

/**
 * Protocol Buffer codec for encoding/decoding messages
 */
class Codec
{
    /**
     * Encode a message with length prefix (for gRPC framing)
     */
    public static function encodeWithLengthPrefix(string $data, bool $compressed = false): string
    {
        $compressionFlag = $compressed ? "\x01" : "\x00";
        $length = pack('N', strlen($data));
        return $compressionFlag . $length . $data;
    }

    /**
     * Decode a length-prefixed message
     *
     * @return array{compressed: bool, data: string, remaining: string}
     */
    public static function decodeWithLengthPrefix(string $data): array
    {
        if (strlen($data) < 5) {
            throw new \InvalidArgumentException('Data too short for length-prefixed message');
        }

        $compressed = ord($data[0]) === 1;
        $length = unpack('N', substr($data, 1, 4))[1];
        
        if (strlen($data) < 5 + $length) {
            throw new \InvalidArgumentException('Data shorter than declared length');
        }

        return [
            'compressed' => $compressed,
            'data' => substr($data, 5, $length),
            'remaining' => substr($data, 5 + $length),
        ];
    }

    /**
     * Encode multiple messages (for streaming)
     *
     * @param array<string> $messages
     */
    public static function encodeMultiple(array $messages, bool $compressed = false): string
    {
        $result = '';
        foreach ($messages as $message) {
            $result .= self::encodeWithLengthPrefix($message, $compressed);
        }
        return $result;
    }

    /**
     * Decode multiple messages from a stream
     *
     * @return array<array{compressed: bool, data: string}>
     */
    public static function decodeMultiple(string $data): array
    {
        $messages = [];
        
        while (strlen($data) >= 5) {
            $decoded = self::decodeWithLengthPrefix($data);
            $messages[] = [
                'compressed' => $decoded['compressed'],
                'data' => $decoded['data'],
            ];
            $data = $decoded['remaining'];
        }

        return $messages;
    }

    /**
     * Compress data using gzip
     */
    public static function compress(string $data): string
    {
        return gzencode($data, 6);
    }

    /**
     * Decompress gzip data
     */
    public static function decompress(string $data): string
    {
        $result = gzdecode($data);
        if ($result === false) {
            throw new \RuntimeException('Failed to decompress data');
        }
        return $result;
    }
}
