<?php

declare(strict_types=1);

namespace HybridPHP\Core\GraphQL\Parser;

/**
 * Base AST Node
 */
abstract class Node
{
    public string $kind;

    public function __construct(string $kind)
    {
        $this->kind = $kind;
    }
}

// Document nodes

class DocumentNode extends Node
{
    public array $definitions;

    public function __construct(array $definitions)
    {
        parent::__construct('Document');
        $this->definitions = $definitions;
    }
}

abstract class DefinitionNode extends Node {}

class OperationDefinitionNode extends DefinitionNode
{
    public string $operation;
    public ?string $name;
    public array $variableDefinitions;
    public array $directives;
    public SelectionSetNode $selectionSet;

    public function __construct(
        string $operation,
        ?string $name,
        array $variableDefinitions,
        array $directives,
        SelectionSetNode $selectionSet
    ) {
        parent::__construct('OperationDefinition');
        $this->operation = $operation;
        $this->name = $name;
        $this->variableDefinitions = $variableDefinitions;
        $this->directives = $directives;
        $this->selectionSet = $selectionSet;
    }
}

class FragmentDefinitionNode extends DefinitionNode
{
    public string $name;
    public string $typeCondition;
    public array $directives;
    public SelectionSetNode $selectionSet;

    public function __construct(
        string $name,
        string $typeCondition,
        array $directives,
        SelectionSetNode $selectionSet
    ) {
        parent::__construct('FragmentDefinition');
        $this->name = $name;
        $this->typeCondition = $typeCondition;
        $this->directives = $directives;
        $this->selectionSet = $selectionSet;
    }
}

// Selection nodes

class SelectionSetNode extends Node
{
    public array $selections;

    public function __construct(array $selections)
    {
        parent::__construct('SelectionSet');
        $this->selections = $selections;
    }
}

abstract class SelectionNode extends Node {}

class FieldNode extends SelectionNode
{
    public string $name;
    public ?string $alias;
    public array $arguments;
    public array $directives;
    public ?SelectionSetNode $selectionSet;

    public function __construct(
        string $name,
        ?string $alias,
        array $arguments,
        array $directives,
        ?SelectionSetNode $selectionSet
    ) {
        parent::__construct('Field');
        $this->name = $name;
        $this->alias = $alias;
        $this->arguments = $arguments;
        $this->directives = $directives;
        $this->selectionSet = $selectionSet;
    }

    public function getResponseKey(): string
    {
        return $this->alias ?? $this->name;
    }
}

class FragmentSpreadNode extends SelectionNode
{
    public string $name;
    public array $directives;

    public function __construct(string $name, array $directives)
    {
        parent::__construct('FragmentSpread');
        $this->name = $name;
        $this->directives = $directives;
    }
}

class InlineFragmentNode extends SelectionNode
{
    public ?string $typeCondition;
    public array $directives;
    public SelectionSetNode $selectionSet;

    public function __construct(
        ?string $typeCondition,
        array $directives,
        SelectionSetNode $selectionSet
    ) {
        parent::__construct('InlineFragment');
        $this->typeCondition = $typeCondition;
        $this->directives = $directives;
        $this->selectionSet = $selectionSet;
    }
}

// Variable nodes

class VariableDefinitionNode extends Node
{
    public string $name;
    public TypeNode $type;
    public ?ValueNode $defaultValue;

    public function __construct(string $name, TypeNode $type, ?ValueNode $defaultValue)
    {
        parent::__construct('VariableDefinition');
        $this->name = $name;
        $this->type = $type;
        $this->defaultValue = $defaultValue;
    }
}

// Type nodes

abstract class TypeNode extends Node {}

class NamedTypeNode extends TypeNode
{
    public string $name;

    public function __construct(string $name)
    {
        parent::__construct('NamedType');
        $this->name = $name;
    }
}

class ListTypeNode extends TypeNode
{
    public TypeNode $type;

    public function __construct(TypeNode $type)
    {
        parent::__construct('ListType');
        $this->type = $type;
    }
}

class NonNullTypeNode extends TypeNode
{
    public TypeNode $type;

    public function __construct(TypeNode $type)
    {
        parent::__construct('NonNullType');
        $this->type = $type;
    }
}

// Value nodes

abstract class ValueNode extends Node
{
    abstract public function getValue(): mixed;
}

class VariableNode extends ValueNode
{
    public string $name;

    public function __construct(string $name)
    {
        parent::__construct('Variable');
        $this->name = $name;
    }

    public function getValue(): mixed
    {
        return null; // Variables are resolved at runtime
    }
}

class IntValueNode extends ValueNode
{
    public int $value;

    public function __construct(int $value)
    {
        parent::__construct('IntValue');
        $this->value = $value;
    }

    public function getValue(): int
    {
        return $this->value;
    }
}

class FloatValueNode extends ValueNode
{
    public float $value;

    public function __construct(float $value)
    {
        parent::__construct('FloatValue');
        $this->value = $value;
    }

    public function getValue(): float
    {
        return $this->value;
    }
}

class StringValueNode extends ValueNode
{
    public string $value;

    public function __construct(string $value)
    {
        parent::__construct('StringValue');
        $this->value = $value;
    }

    public function getValue(): string
    {
        return $this->value;
    }
}

class BooleanValueNode extends ValueNode
{
    public bool $value;

    public function __construct(bool $value)
    {
        parent::__construct('BooleanValue');
        $this->value = $value;
    }

    public function getValue(): bool
    {
        return $this->value;
    }
}

class NullValueNode extends ValueNode
{
    public function __construct()
    {
        parent::__construct('NullValue');
    }

    public function getValue(): mixed
    {
        return null;
    }
}

class EnumValueNode extends ValueNode
{
    public string $value;

    public function __construct(string $value)
    {
        parent::__construct('EnumValue');
        $this->value = $value;
    }

    public function getValue(): string
    {
        return $this->value;
    }
}

class ListValueNode extends ValueNode
{
    public array $values;

    public function __construct(array $values)
    {
        parent::__construct('ListValue');
        $this->values = $values;
    }

    public function getValue(): array
    {
        return array_map(fn($v) => $v->getValue(), $this->values);
    }
}

class ObjectValueNode extends ValueNode
{
    public array $fields;

    public function __construct(array $fields)
    {
        parent::__construct('ObjectValue');
        $this->fields = $fields;
    }

    public function getValue(): array
    {
        $result = [];
        foreach ($this->fields as $name => $value) {
            $result[$name] = $value->getValue();
        }
        return $result;
    }
}

// Argument and Directive nodes

class ArgumentNode extends Node
{
    public string $name;
    public ValueNode $value;

    public function __construct(string $name, ValueNode $value)
    {
        parent::__construct('Argument');
        $this->name = $name;
        $this->value = $value;
    }
}

class DirectiveNode extends Node
{
    public string $name;
    public array $arguments;

    public function __construct(string $name, array $arguments)
    {
        parent::__construct('Directive');
        $this->name = $name;
        $this->arguments = $arguments;
    }
}
