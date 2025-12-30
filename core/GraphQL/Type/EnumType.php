<?php

declare(strict_types=1);

namespace HybridPHP\Core\GraphQL\Type;

/**
 * GraphQL Enum Type
 */
class EnumType implements TypeInterface
{
    protected string $name;
    protected ?string $description;
    protected array $values = [];

    public function __construct(array $config)
    {
        $this->name = $config['name'];
        $this->description = $config['description'] ?? null;

        if (isset($config['values'])) {
            foreach ($config['values'] as $name => $valueConfig) {
                if (is_string($valueConfig)) {
                    $this->values[$name] = new EnumValueDefinition([
                        'name' => $name,
                        'value' => $valueConfig,
                    ]);
                } elseif (is_array($valueConfig)) {
                    $valueConfig['name'] = $name;
                    if (!isset($valueConfig['value'])) {
                        $valueConfig['value'] = $name;
                    }
                    $this->values[$name] = new EnumValueDefinition($valueConfig);
                } elseif ($valueConfig instanceof EnumValueDefinition) {
                    $this->values[$name] = $valueConfig;
                }
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
     * Get all enum values
     */
    public function getValues(): array
    {
        return $this->values;
    }

    /**
     * Get a specific enum value by name
     */
    public function getValue(string $name): ?EnumValueDefinition
    {
        return $this->values[$name] ?? null;
    }

    public function serialize(mixed $value): string
    {
        foreach ($this->values as $enumValue) {
            if ($enumValue->value === $value) {
                return $enumValue->name;
            }
        }
        throw new \InvalidArgumentException("Enum '{$this->name}' cannot represent value: " . json_encode($value));
    }

    public function parseValue(mixed $value): mixed
    {
        if (isset($this->values[$value])) {
            return $this->values[$value]->value;
        }
        throw new \InvalidArgumentException("Value '{$value}' is not a valid enum value for '{$this->name}'");
    }

    public function isLeafType(): bool
    {
        return true;
    }
}

/**
 * Enum value definition
 */
class EnumValueDefinition
{
    public string $name;
    public mixed $value;
    public ?string $description;
    public ?string $deprecationReason;

    public function __construct(array $config)
    {
        $this->name = $config['name'];
        $this->value = $config['value'] ?? $config['name'];
        $this->description = $config['description'] ?? null;
        $this->deprecationReason = $config['deprecationReason'] ?? null;
    }

    /**
     * Check if value is deprecated
     */
    public function isDeprecated(): bool
    {
        return $this->deprecationReason !== null;
    }
}
