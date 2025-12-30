<?php

declare(strict_types=1);

namespace HybridPHP\Core\Grpc\Protobuf;

/**
 * Abstract base class for Protocol Buffer messages
 * 
 * This provides a simple implementation for frameworks that don't use
 * the official Google protobuf extension. For production use with
 * complex schemas, use google/protobuf package.
 */
abstract class AbstractMessage implements MessageInterface
{
    protected array $data = [];

    /**
     * Serialize message to binary format (simplified varint encoding)
     */
    public function serializeToString(): string
    {
        $result = '';
        
        foreach ($this->getFieldDescriptors() as $fieldNumber => $descriptor) {
            $value = $this->data[$descriptor['name']] ?? null;
            
            if ($value === null) {
                continue;
            }

            $wireType = $this->getWireType($descriptor['type']);
            $tag = ($fieldNumber << 3) | $wireType;
            $result .= $this->encodeVarint($tag);
            $result .= $this->encodeValue($value, $descriptor['type']);
        }

        return $result;
    }

    /**
     * Parse message from binary format
     */
    public function mergeFromString(string $data): void
    {
        $pos = 0;
        $len = strlen($data);
        $descriptors = $this->getFieldDescriptors();

        while ($pos < $len) {
            [$tag, $pos] = $this->decodeVarint($data, $pos);
            $fieldNumber = $tag >> 3;
            $wireType = $tag & 0x07;

            if (!isset($descriptors[$fieldNumber])) {
                // Skip unknown field
                $pos = $this->skipField($data, $pos, $wireType);
                continue;
            }

            $descriptor = $descriptors[$fieldNumber];
            [$value, $pos] = $this->decodeValue($data, $pos, $descriptor['type'], $wireType);
            
            if ($descriptor['repeated'] ?? false) {
                $this->data[$descriptor['name']][] = $value;
            } else {
                $this->data[$descriptor['name']] = $value;
            }
        }
    }

    /**
     * Serialize message to JSON
     */
    public function serializeToJsonString(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Parse message from JSON
     */
    public function mergeFromJsonString(string $data): void
    {
        $decoded = json_decode($data, true);
        if (is_array($decoded)) {
            $this->fromArray($decoded);
        }
    }

    /**
     * Clear all fields
     */
    public function clear(): void
    {
        $this->data = [];
    }

    /**
     * Convert message to array
     */
    public function toArray(): array
    {
        $result = [];
        
        foreach ($this->getFieldDescriptors() as $descriptor) {
            $name = $descriptor['name'];
            $value = $this->data[$name] ?? null;
            
            if ($value === null) {
                continue;
            }

            if ($value instanceof MessageInterface) {
                $result[$name] = $value->toArray();
            } elseif (is_array($value)) {
                $result[$name] = array_map(
                    fn($v) => $v instanceof MessageInterface ? $v->toArray() : $v,
                    $value
                );
            } else {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    /**
     * Populate message from array
     */
    public function fromArray(array $data): void
    {
        foreach ($this->getFieldDescriptors() as $descriptor) {
            $name = $descriptor['name'];
            if (isset($data[$name])) {
                $this->data[$name] = $data[$name];
            }
        }
    }

    /**
     * Get field descriptors (to be implemented by subclasses)
     *
     * @return array<int, array{name: string, type: string, repeated?: bool}>
     */
    abstract protected function getFieldDescriptors(): array;

    /**
     * Get wire type for protobuf type
     */
    protected function getWireType(string $type): int
    {
        return match ($type) {
            'int32', 'int64', 'uint32', 'uint64', 'sint32', 'sint64', 'bool', 'enum' => 0,
            'fixed64', 'sfixed64', 'double' => 1,
            'string', 'bytes', 'message' => 2,
            'fixed32', 'sfixed32', 'float' => 5,
            default => 2,
        };
    }

    /**
     * Encode a varint
     */
    protected function encodeVarint(int $value): string
    {
        $result = '';
        
        if ($value < 0) {
            // Handle negative numbers (use 10 bytes for 64-bit)
            $value = $value & 0xFFFFFFFFFFFFFFFF;
        }

        do {
            $byte = $value & 0x7F;
            $value >>= 7;
            if ($value > 0) {
                $byte |= 0x80;
            }
            $result .= chr($byte);
        } while ($value > 0);

        return $result;
    }

    /**
     * Decode a varint
     *
     * @return array{0: int, 1: int} [value, new position]
     */
    protected function decodeVarint(string $data, int $pos): array
    {
        $result = 0;
        $shift = 0;

        do {
            $byte = ord($data[$pos++]);
            $result |= ($byte & 0x7F) << $shift;
            $shift += 7;
        } while ($byte & 0x80);

        return [$result, $pos];
    }

    /**
     * Encode a value based on type
     */
    protected function encodeValue(mixed $value, string $type): string
    {
        return match ($type) {
            'int32', 'int64', 'uint32', 'uint64', 'enum' => $this->encodeVarint((int)$value),
            'sint32' => $this->encodeVarint($this->zigzagEncode32((int)$value)),
            'sint64' => $this->encodeVarint($this->zigzagEncode64((int)$value)),
            'bool' => $this->encodeVarint($value ? 1 : 0),
            'string', 'bytes' => $this->encodeVarint(strlen($value)) . $value,
            'message' => $this->encodeMessage($value),
            'fixed32', 'sfixed32' => pack('V', (int)$value),
            'fixed64', 'sfixed64' => pack('P', (int)$value),
            'float' => pack('g', (float)$value),
            'double' => pack('e', (float)$value),
            default => '',
        };
    }

    /**
     * Decode a value based on type
     *
     * @return array{0: mixed, 1: int} [value, new position]
     */
    protected function decodeValue(string $data, int $pos, string $type, int $wireType): array
    {
        return match ($type) {
            'int32', 'int64', 'uint32', 'uint64', 'enum' => $this->decodeVarint($data, $pos),
            'sint32' => $this->decodeSint32($data, $pos),
            'sint64' => $this->decodeSint64($data, $pos),
            'bool' => $this->decodeBool($data, $pos),
            'string', 'bytes' => $this->decodeString($data, $pos),
            'fixed32' => $this->decodeFixed32($data, $pos),
            'sfixed32' => $this->decodeSfixed32($data, $pos),
            'fixed64' => $this->decodeFixed64($data, $pos),
            'sfixed64' => $this->decodeSfixed64($data, $pos),
            'float' => $this->decodeFloat($data, $pos),
            'double' => $this->decodeDouble($data, $pos),
            default => $this->decodeString($data, $pos),
        };
    }

    protected function encodeMessage(MessageInterface $message): string
    {
        $serialized = $message->serializeToString();
        return $this->encodeVarint(strlen($serialized)) . $serialized;
    }

    protected function zigzagEncode32(int $n): int
    {
        return ($n << 1) ^ ($n >> 31);
    }

    protected function zigzagEncode64(int $n): int
    {
        return ($n << 1) ^ ($n >> 63);
    }

    protected function decodeSint32(string $data, int $pos): array
    {
        [$value, $pos] = $this->decodeVarint($data, $pos);
        return [($value >> 1) ^ -($value & 1), $pos];
    }

    protected function decodeSint64(string $data, int $pos): array
    {
        [$value, $pos] = $this->decodeVarint($data, $pos);
        return [($value >> 1) ^ -($value & 1), $pos];
    }

    protected function decodeBool(string $data, int $pos): array
    {
        [$value, $pos] = $this->decodeVarint($data, $pos);
        return [$value !== 0, $pos];
    }

    protected function decodeString(string $data, int $pos): array
    {
        [$length, $pos] = $this->decodeVarint($data, $pos);
        $value = substr($data, $pos, $length);
        return [$value, $pos + $length];
    }

    protected function decodeFixed32(string $data, int $pos): array
    {
        $value = unpack('V', substr($data, $pos, 4))[1];
        return [$value, $pos + 4];
    }

    protected function decodeSfixed32(string $data, int $pos): array
    {
        $value = unpack('l', substr($data, $pos, 4))[1];
        return [$value, $pos + 4];
    }

    protected function decodeFixed64(string $data, int $pos): array
    {
        $value = unpack('P', substr($data, $pos, 8))[1];
        return [$value, $pos + 8];
    }

    protected function decodeSfixed64(string $data, int $pos): array
    {
        $value = unpack('q', substr($data, $pos, 8))[1];
        return [$value, $pos + 8];
    }

    protected function decodeFloat(string $data, int $pos): array
    {
        $value = unpack('g', substr($data, $pos, 4))[1];
        return [$value, $pos + 4];
    }

    protected function decodeDouble(string $data, int $pos): array
    {
        $value = unpack('e', substr($data, $pos, 8))[1];
        return [$value, $pos + 8];
    }

    protected function skipField(string $data, int $pos, int $wireType): int
    {
        return match ($wireType) {
            0 => $this->decodeVarint($data, $pos)[1],
            1 => $pos + 8,
            2 => $this->decodeString($data, $pos)[1],
            5 => $pos + 4,
            default => $pos,
        };
    }

    /**
     * Magic getter
     */
    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    /**
     * Magic setter
     */
    public function __set(string $name, mixed $value): void
    {
        $this->data[$name] = $value;
    }

    /**
     * Magic isset
     */
    public function __isset(string $name): bool
    {
        return isset($this->data[$name]);
    }
}
