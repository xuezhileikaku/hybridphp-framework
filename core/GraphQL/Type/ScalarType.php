<?php

declare(strict_types=1);

namespace HybridPHP\Core\GraphQL\Type;

/**
 * Base class for scalar types
 */
abstract class ScalarType implements TypeInterface
{
    protected string $name;
    protected ?string $description = null;

    public function __construct(string $name, ?string $description = null)
    {
        $this->name = $name;
        $this->description = $description;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function isLeafType(): bool
    {
        return true;
    }
}

/**
 * Built-in String scalar type
 */
class StringType extends ScalarType
{
    public function __construct()
    {
        parent::__construct('String', 'The `String` scalar type represents textual data');
    }

    public function serialize(mixed $value): string
    {
        return (string) $value;
    }

    public function parseValue(mixed $value): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            throw new \InvalidArgumentException('String cannot represent non-string value');
        }
        return (string) $value;
    }
}

/**
 * Built-in Int scalar type
 */
class IntType extends ScalarType
{
    public function __construct()
    {
        parent::__construct('Int', 'The `Int` scalar type represents non-fractional signed whole numeric values');
    }

    public function serialize(mixed $value): int
    {
        return (int) $value;
    }

    public function parseValue(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException('Int cannot represent non-numeric value');
        }
        return (int) $value;
    }
}

/**
 * Built-in Float scalar type
 */
class FloatType extends ScalarType
{
    public function __construct()
    {
        parent::__construct('Float', 'The `Float` scalar type represents signed double-precision fractional values');
    }

    public function serialize(mixed $value): float
    {
        return (float) $value;
    }

    public function parseValue(mixed $value): float
    {
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException('Float cannot represent non-numeric value');
        }
        return (float) $value;
    }
}

/**
 * Built-in Boolean scalar type
 */
class BooleanType extends ScalarType
{
    public function __construct()
    {
        parent::__construct('Boolean', 'The `Boolean` scalar type represents `true` or `false`');
    }

    public function serialize(mixed $value): bool
    {
        return (bool) $value;
    }

    public function parseValue(mixed $value): bool
    {
        if (!is_bool($value) && !is_numeric($value)) {
            throw new \InvalidArgumentException('Boolean cannot represent non-boolean value');
        }
        return (bool) $value;
    }
}

/**
 * Built-in ID scalar type
 */
class IDType extends ScalarType
{
    public function __construct()
    {
        parent::__construct('ID', 'The `ID` scalar type represents a unique identifier');
    }

    public function serialize(mixed $value): string
    {
        return (string) $value;
    }

    public function parseValue(mixed $value): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new \InvalidArgumentException('ID cannot represent non-string/non-int value');
        }
        return (string) $value;
    }
}
