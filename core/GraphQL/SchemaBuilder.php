<?php

declare(strict_types=1);

namespace HybridPHP\Core\GraphQL;

use HybridPHP\Core\GraphQL\Type\ObjectType;
use HybridPHP\Core\GraphQL\Type\InputObjectType;
use HybridPHP\Core\GraphQL\Type\EnumType;
use HybridPHP\Core\GraphQL\Type\InterfaceType;
use HybridPHP\Core\GraphQL\Type\UnionType;
use HybridPHP\Core\GraphQL\Type\ListType;
use HybridPHP\Core\GraphQL\Type\NonNullType;
use HybridPHP\Core\GraphQL\Type\TypeInterface;

/**
 * Fluent Schema Builder for GraphQL
 */
class SchemaBuilder
{
    protected ?ObjectType $queryType = null;
    protected ?ObjectType $mutationType = null;
    protected ?ObjectType $subscriptionType = null;
    protected array $types = [];

    /**
     * Create a new schema builder
     */
    public static function create(): self
    {
        return new self();
    }

    /**
     * Set the query type
     */
    public function query(ObjectType|array $type): self
    {
        $this->queryType = $type instanceof ObjectType ? $type : new ObjectType($type);
        return $this;
    }

    /**
     * Set the mutation type
     */
    public function mutation(ObjectType|array $type): self
    {
        $this->mutationType = $type instanceof ObjectType ? $type : new ObjectType($type);
        return $this;
    }

    /**
     * Set the subscription type
     */
    public function subscription(ObjectType|array $type): self
    {
        $this->subscriptionType = $type instanceof ObjectType ? $type : new ObjectType($type);
        return $this;
    }

    /**
     * Add a type to the schema
     */
    public function addType(TypeInterface $type): self
    {
        $this->types[] = $type;
        return $this;
    }

    /**
     * Build the schema
     */
    public function build(): Schema
    {
        return new Schema([
            'query' => $this->queryType,
            'mutation' => $this->mutationType,
            'subscription' => $this->subscriptionType,
            'types' => $this->types,
        ]);
    }

    // Type factory methods

    /**
     * Create an object type
     */
    public static function objectType(string $name, array $config = []): ObjectType
    {
        $config['name'] = $name;
        return new ObjectType($config);
    }

    /**
     * Create an input object type
     */
    public static function inputType(string $name, array $config = []): InputObjectType
    {
        $config['name'] = $name;
        return new InputObjectType($config);
    }

    /**
     * Create an enum type
     */
    public static function enumType(string $name, array $values, ?string $description = null): EnumType
    {
        return new EnumType([
            'name' => $name,
            'description' => $description,
            'values' => $values,
        ]);
    }

    /**
     * Create an interface type
     */
    public static function interfaceType(string $name, array $config = []): InterfaceType
    {
        $config['name'] = $name;
        return new InterfaceType($config);
    }

    /**
     * Create a union type
     */
    public static function unionType(string $name, array $types, ?callable $resolveType = null): UnionType
    {
        return new UnionType([
            'name' => $name,
            'types' => $types,
            'resolveType' => $resolveType,
        ]);
    }

    /**
     * Create a list type
     */
    public static function listOf(TypeInterface|string $type): ListType
    {
        return new ListType($type);
    }

    /**
     * Create a non-null type
     */
    public static function nonNull(TypeInterface|string $type): NonNullType
    {
        return new NonNullType($type);
    }

    // Scalar type shortcuts

    public static function string(): string
    {
        return 'String';
    }

    public static function int(): string
    {
        return 'Int';
    }

    public static function float(): string
    {
        return 'Float';
    }

    public static function boolean(): string
    {
        return 'Boolean';
    }

    public static function id(): string
    {
        return 'ID';
    }
}

/**
 * Field builder for fluent field definitions
 */
class FieldBuilder
{
    protected array $config = [];

    public function __construct(string $name)
    {
        $this->config['name'] = $name;
    }

    public static function create(string $name): self
    {
        return new self($name);
    }

    public function type(TypeInterface|string $type): self
    {
        $this->config['type'] = $type;
        return $this;
    }

    public function description(string $description): self
    {
        $this->config['description'] = $description;
        return $this;
    }

    public function args(array $args): self
    {
        $this->config['args'] = $args;
        return $this;
    }

    public function resolve(callable $resolver): self
    {
        $this->config['resolve'] = $resolver;
        return $this;
    }

    public function deprecate(string $reason): self
    {
        $this->config['deprecationReason'] = $reason;
        return $this;
    }

    public function build(): array
    {
        return $this->config;
    }
}

/**
 * Argument builder for fluent argument definitions
 */
class ArgumentBuilder
{
    protected array $config = [];

    public function __construct(string $name)
    {
        $this->config['name'] = $name;
    }

    public static function create(string $name): self
    {
        return new self($name);
    }

    public function type(TypeInterface|string $type): self
    {
        $this->config['type'] = $type;
        return $this;
    }

    public function description(string $description): self
    {
        $this->config['description'] = $description;
        return $this;
    }

    public function defaultValue(mixed $value): self
    {
        $this->config['defaultValue'] = $value;
        return $this;
    }

    public function build(): array
    {
        return $this->config;
    }
}
