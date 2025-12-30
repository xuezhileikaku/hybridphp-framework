<?php

declare(strict_types=1);

namespace HybridPHP\Core\GraphQL\Type;

use Closure;

/**
 * GraphQL Interface Type
 */
class InterfaceType implements TypeInterface
{
    protected string $name;
    protected ?string $description;
    protected array $fields = [];
    protected ?Closure $fieldsThunk = null;
    protected ?Closure $resolveType = null;
    protected bool $fieldsResolved = false;

    public function __construct(array $config)
    {
        $this->name = $config['name'];
        $this->description = $config['description'] ?? null;
        $this->resolveType = $config['resolveType'] ?? null;

        if (isset($config['fields'])) {
            if ($config['fields'] instanceof Closure) {
                $this->fieldsThunk = $config['fields'];
            } else {
                $this->fields = $this->normalizeFields($config['fields']);
                $this->fieldsResolved = true;
            }
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Get all fields for this interface
     */
    public function getFields(): array
    {
        if (!$this->fieldsResolved && $this->fieldsThunk !== null) {
            $this->fields = $this->normalizeFields(($this->fieldsThunk)());
            $this->fieldsResolved = true;
        }
        return $this->fields;
    }

    /**
     * Get a specific field by name
     */
    public function getField(string $name): ?FieldDefinition
    {
        $fields = $this->getFields();
        return $fields[$name] ?? null;
    }

    /**
     * Resolve the concrete type for a value
     */
    public function resolveType(mixed $value, mixed $context, array $info): ?ObjectType
    {
        if ($this->resolveType !== null) {
            return ($this->resolveType)($value, $context, $info);
        }
        return null;
    }

    public function serialize(mixed $value): mixed
    {
        return $value;
    }

    public function parseValue(mixed $value): mixed
    {
        return $value;
    }

    public function isLeafType(): bool
    {
        return false;
    }

    /**
     * Normalize field definitions
     */
    protected function normalizeFields(array $fields): array
    {
        $normalized = [];
        foreach ($fields as $name => $config) {
            if ($config instanceof FieldDefinition) {
                $normalized[$name] = $config;
            } else {
                $config['name'] = $name;
                $normalized[$name] = new FieldDefinition($config);
            }
        }
        return $normalized;
    }
}

/**
 * GraphQL Union Type
 */
class UnionType implements TypeInterface
{
    protected string $name;
    protected ?string $description;
    protected array $types = [];
    protected ?Closure $resolveType = null;

    public function __construct(array $config)
    {
        $this->name = $config['name'];
        $this->description = $config['description'] ?? null;
        $this->types = $config['types'] ?? [];
        $this->resolveType = $config['resolveType'] ?? null;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Get all possible types in this union
     */
    public function getTypes(): array
    {
        return $this->types;
    }

    /**
     * Resolve the concrete type for a value
     */
    public function resolveType(mixed $value, mixed $context, array $info): ?ObjectType
    {
        if ($this->resolveType !== null) {
            return ($this->resolveType)($value, $context, $info);
        }
        return null;
    }

    public function serialize(mixed $value): mixed
    {
        return $value;
    }

    public function parseValue(mixed $value): mixed
    {
        return $value;
    }

    public function isLeafType(): bool
    {
        return false;
    }
}
