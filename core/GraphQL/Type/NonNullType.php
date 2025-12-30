<?php

declare(strict_types=1);

namespace HybridPHP\Core\GraphQL\Type;

/**
 * GraphQL Non-Null Type wrapper
 */
class NonNullType implements TypeInterface
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
        return "{$innerName}!";
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

    public function serialize(mixed $value): mixed
    {
        if ($value === null) {
            throw new \InvalidArgumentException('Non-null type cannot be null');
        }

        if ($this->ofType instanceof TypeInterface) {
            return $this->ofType->serialize($value);
        }
        return $value;
    }

    public function parseValue(mixed $value): mixed
    {
        if ($value === null) {
            throw new \InvalidArgumentException('Non-null type cannot be null');
        }

        if ($this->ofType instanceof TypeInterface) {
            return $this->ofType->parseValue($value);
        }
        return $value;
    }

    public function isLeafType(): bool
    {
        if ($this->ofType instanceof TypeInterface) {
            return $this->ofType->isLeafType();
        }
        return false;
    }
}
