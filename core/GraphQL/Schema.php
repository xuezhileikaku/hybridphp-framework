<?php

declare(strict_types=1);

namespace HybridPHP\Core\GraphQL;

use HybridPHP\Core\GraphQL\Type\TypeInterface;
use HybridPHP\Core\GraphQL\Type\ObjectType;
use HybridPHP\Core\GraphQL\Type\ScalarType;
use HybridPHP\Core\GraphQL\Type\StringType;
use HybridPHP\Core\GraphQL\Type\IntType;
use HybridPHP\Core\GraphQL\Type\FloatType;
use HybridPHP\Core\GraphQL\Type\BooleanType;
use HybridPHP\Core\GraphQL\Type\IDType;

/**
 * GraphQL Schema definition
 */
class Schema
{
    protected ?ObjectType $queryType = null;
    protected ?ObjectType $mutationType = null;
    protected ?ObjectType $subscriptionType = null;
    protected array $types = [];
    protected array $directives = [];
    protected bool $initialized = false;

    /**
     * Built-in scalar types
     */
    protected static array $builtInTypes = [];

    public function __construct(array $config = [])
    {
        $this->initBuiltInTypes();

        if (isset($config['query'])) {
            $this->queryType = $this->normalizeType($config['query']);
        }

        if (isset($config['mutation'])) {
            $this->mutationType = $this->normalizeType($config['mutation']);
        }

        if (isset($config['subscription'])) {
            $this->subscriptionType = $this->normalizeType($config['subscription']);
        }

        if (isset($config['types'])) {
            foreach ($config['types'] as $type) {
                $this->addType($type);
            }
        }

        if (isset($config['directives'])) {
            $this->directives = $config['directives'];
        }

        $this->initialized = true;
    }

    /**
     * Initialize built-in scalar types
     */
    protected function initBuiltInTypes(): void
    {
        if (empty(self::$builtInTypes)) {
            self::$builtInTypes = [
                'String' => new StringType(),
                'Int' => new IntType(),
                'Float' => new FloatType(),
                'Boolean' => new BooleanType(),
                'ID' => new IDType(),
            ];
        }

        // Add built-in types to schema
        foreach (self::$builtInTypes as $name => $type) {
            $this->types[$name] = $type;
        }
    }

    /**
     * Get the query type
     */
    public function getQueryType(): ?ObjectType
    {
        return $this->queryType;
    }

    /**
     * Get the mutation type
     */
    public function getMutationType(): ?ObjectType
    {
        return $this->mutationType;
    }

    /**
     * Get the subscription type
     */
    public function getSubscriptionType(): ?ObjectType
    {
        return $this->subscriptionType;
    }

    /**
     * Get a type by name
     */
    public function getType(string $name): ?TypeInterface
    {
        return $this->types[$name] ?? null;
    }

    /**
     * Get all types in the schema
     */
    public function getTypes(): array
    {
        return $this->types;
    }

    /**
     * Add a type to the schema
     */
    public function addType(TypeInterface|array $type): void
    {
        $type = $this->normalizeType($type);
        $this->types[$type->getName()] = $type;
    }

    /**
     * Check if a type exists
     */
    public function hasType(string $name): bool
    {
        return isset($this->types[$name]);
    }

    /**
     * Get directives
     */
    public function getDirectives(): array
    {
        return $this->directives;
    }

    /**
     * Normalize type definition
     */
    protected function normalizeType(TypeInterface|array $type): TypeInterface
    {
        if ($type instanceof TypeInterface) {
            return $type;
        }

        if (is_array($type)) {
            return new ObjectType($type);
        }

        throw new \InvalidArgumentException('Invalid type definition');
    }

    /**
     * Get a built-in scalar type
     */
    public static function string(): StringType
    {
        return self::$builtInTypes['String'] ?? new StringType();
    }

    public static function int(): IntType
    {
        return self::$builtInTypes['Int'] ?? new IntType();
    }

    public static function float(): FloatType
    {
        return self::$builtInTypes['Float'] ?? new FloatType();
    }

    public static function boolean(): BooleanType
    {
        return self::$builtInTypes['Boolean'] ?? new BooleanType();
    }

    public static function id(): IDType
    {
        return self::$builtInTypes['ID'] ?? new IDType();
    }

    /**
     * Validate the schema
     */
    public function validate(): array
    {
        $errors = [];

        if ($this->queryType === null) {
            $errors[] = 'Schema must have a Query type';
        }

        // Validate all types
        foreach ($this->types as $type) {
            if ($type instanceof ObjectType) {
                $typeErrors = $this->validateObjectType($type);
                $errors = array_merge($errors, $typeErrors);
            }
        }

        return $errors;
    }

    /**
     * Validate an object type
     */
    protected function validateObjectType(ObjectType $type): array
    {
        $errors = [];
        $fields = $type->getFields();

        if (empty($fields)) {
            $errors[] = "Type '{$type->getName()}' must have at least one field";
        }

        foreach ($fields as $field) {
            $fieldType = $field->type;
            if (is_string($fieldType) && !$this->hasType($fieldType)) {
                $errors[] = "Field '{$field->name}' references unknown type '{$fieldType}'";
            }
        }

        return $errors;
    }
}
