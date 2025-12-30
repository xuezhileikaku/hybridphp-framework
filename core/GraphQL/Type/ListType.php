<?php

declare(strict_types=1);

namespace HybridPHP\Core\GraphQL\Type;

/**
 * GraphQL List Type wrapper
 */
class ListType implements TypeInterface
{
    protected TypeInterface|string $ofType;

    public function __construct(TypeInterface|string $ofType)
    {
        $this->ofType = $ofType;
    }

    public function getName(): string
    {
        $innerName = $this->ofType instanceof TypeInterface 
            ? $this->ofType->getName() 
            : $this->ofType;
        return "[{$innerName}]";
    }

    public function getDescription(): ?string
    {
        return null;
    }

    /**
     * Get the wrapped type
     */
    public function getOfType(): TypeInterface|string
    {
        return $this->ofType;
    }

    public function serialize(mixed $value): array
    {
        if (!is_array($value) && !($value instanceof \Traversable)) {
            throw new \InvalidArgumentException('List type expects an array or traversable');
        }

        $result = [];
        foreach ($value as $item) {
            if ($this->ofType instanceof TypeInterface) {
                $result[] = $this->ofType->serialize($item);
            } else {
                $result[] = $item;
            }
        }
        return $result;
    }

    public function parseValue(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('List type expects an array');
        }

        $result = [];
        foreach ($value as $item) {
            if ($this->ofType instanceof TypeInterface) {
                $result[] = $this->ofType->parseValue($item);
            } else {
                $result[] = $item;
            }
        }
        return $result;
    }

    public function isLeafType(): bool
    {
        return false;
    }
}
