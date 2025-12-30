<?php

declare(strict_types=1);

namespace HybridPHP\Core\GraphQL\Type;

use Closure;

/**
 * GraphQL Input Object Type for mutation arguments
 */
class InputObjectType implements TypeInterface
{
    protected string $name;
    protected ?string $description;
    protected array $fields = [];
    protected ?Closure $fieldsThunk = null;
    protected bool $fieldsResolved = false;

    public function __construct(array $config)
    {
        $this->name = $config['name'];
        $this->description = $config['description'] ?? null;

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
     * Get all input fields
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
    public function getField(string $name): ?InputFieldDefinition
    {
        $fields = $this->getFields();
        return $fields[$name] ?? null;
    }

    public function serialize(mixed $value): mixed
    {
        return $value;
    }

    public function parseValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException("Input object must be an array");
        }

        $result = [];
        foreach ($this->getFields() as $fieldName => $field) {
            if (array_key_exists($fieldName, $value)) {
                $result[$fieldName] = $value[$fieldName];
            } elseif ($field->hasDefaultValue) {
                $result[$fieldName] = $field->defaultValue;
            }
        }

        return $result;
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
            if ($config instanceof InputFieldDefinition) {
                $normalized[$name] = $config;
            } else {
                $config['name'] = $name;
                $normalized[$name] = new InputFieldDefinition($config);
            }
        }
        return $normalized;
    }
}

/**
 * Input field definition
 */
class InputFieldDefinition
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
