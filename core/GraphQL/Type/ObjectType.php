<?php

declare(strict_types=1);

namespace HybridPHP\Core\GraphQL\Type;

use Closure;

/**
 * GraphQL Object Type
 */
class ObjectType implements TypeInterface
{
    protected string $name;
    protected ?string $description;
    protected array $fields = [];
    protected ?Closure $fieldsThunk = null;
    protected array $interfaces = [];
    protected bool $fieldsResolved = false;

    public function __construct(array $config)
    {
        $this->name = $config['name'];
        $this->description = $config['description'] ?? null;
        $this->interfaces = $config['interfaces'] ?? [];

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
     * Get all fields for this type
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
     * Check if field exists
     */
    public function hasField(string $name): bool
    {
        return isset($this->getFields()[$name]);
    }

    /**
     * Get interfaces this type implements
     */
    public function getInterfaces(): array
    {
        return $this->interfaces;
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
 * Field definition for object types
 */
class FieldDefinition
{
    public string $name;
    public TypeInterface|string $type;
    public ?string $description;
    public array $args = [];
    public ?Closure $resolve = null;
    public ?string $deprecationReason = null;

    public function __construct(array $config)
    {
        $this->name = $config['name'];
        $this->type = $config['type'];
        $this->description = $config['description'] ?? null;
        $this->deprecationReason = $config['deprecationReason'] ?? null;

        if (isset($config['args'])) {
            foreach ($config['args'] as $argName => $argConfig) {
                if ($argConfig instanceof ArgumentDefinition) {
                    $this->args[$argName] = $argConfig;
                } else {
                    $argConfig['name'] = $argName;
                    $this->args[$argName] = new ArgumentDefinition($argConfig);
                }
            }
        }

        if (isset($config['resolve'])) {
            $this->resolve = $config['resolve'];
        }
    }

    /**
     * Get the resolver function
     */
    public function getResolver(): ?Closure
    {
        return $this->resolve;
    }

    /**
     * Check if field is deprecated
     */
    public function isDeprecated(): bool
    {
        return $this->deprecationReason !== null;
    }
}

/**
 * Argument definition for fields
 */
class ArgumentDefinition
{
    public string $name;
    public TypeInterface|string $type;
    public ?string $description;
    public mixed $defaultValue;
    public bool $hasDefaultValue;

    public function __construct(array $config)
    {
        $this->name = $config['name'];
        $this->type = $config['type'];
        $this->description = $config['description'] ?? null;
        $this->hasDefaultValue = array_key_exists('defaultValue', $config);
        $this->defaultValue = $config['defaultValue'] ?? null;
    }
}
