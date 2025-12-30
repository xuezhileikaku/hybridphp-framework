<?php

declare(strict_types=1);

namespace HybridPHP\Core\GraphQL\Executor;

use Amp\Future;
use HybridPHP\Core\GraphQL\Schema;
use HybridPHP\Core\GraphQL\Parser\Parser;
use HybridPHP\Core\GraphQL\Parser\DocumentNode;
use HybridPHP\Core\GraphQL\Parser\OperationDefinitionNode;
use HybridPHP\Core\GraphQL\Parser\SelectionSetNode;
use HybridPHP\Core\GraphQL\Parser\FieldNode;
use HybridPHP\Core\GraphQL\Parser\FragmentSpreadNode;
use HybridPHP\Core\GraphQL\Parser\InlineFragmentNode;
use HybridPHP\Core\GraphQL\Parser\VariableNode;
use HybridPHP\Core\GraphQL\Parser\ValueNode;
use HybridPHP\Core\GraphQL\Type\ObjectType;
use HybridPHP\Core\GraphQL\Type\TypeInterface;
use HybridPHP\Core\GraphQL\Type\ListType;
use HybridPHP\Core\GraphQL\Type\NonNullType;
use function Amp\async;
use function Amp\Future\await;

/**
 * Async GraphQL Executor
 */
class Executor
{
    protected Schema $schema;
    protected array $fragments = [];
    protected array $variables = [];
    protected mixed $rootValue = null;
    protected mixed $context = null;
    protected array $errors = [];

    public function __construct(Schema $schema)
    {
        $this->schema = $schema;
    }

    /**
     * Execute a GraphQL query asynchronously
     */
    public function execute(
        string|DocumentNode $document,
        ?array $variables = null,
        ?string $operationName = null,
        mixed $rootValue = null,
        mixed $context = null
    ): Future {
        return async(function () use ($document, $variables, $operationName, $rootValue, $context) {
            $this->errors = [];
            $this->variables = $variables ?? [];
            $this->rootValue = $rootValue;
            $this->context = $context;
            $this->fragments = [];

            // Parse if string
            if (is_string($document)) {
                try {
                    $parser = new Parser($document);
                    $document = $parser->parse();
                } catch (\Throwable $e) {
                    return [
                        'data' => null,
                        'errors' => [['message' => $e->getMessage()]],
                    ];
                }
            }

            // Collect fragments
            foreach ($document->definitions as $definition) {
                if ($definition instanceof \HybridPHP\Core\GraphQL\Parser\FragmentDefinitionNode) {
                    $this->fragments[$definition->name] = $definition;
                }
            }

            // Find operation
            $operation = $this->findOperation($document, $operationName);
            if ($operation === null) {
                return [
                    'data' => null,
                    'errors' => [['message' => 'No operation found']],
                ];
            }

            // Get root type
            $rootType = match ($operation->operation) {
                'query' => $this->schema->getQueryType(),
                'mutation' => $this->schema->getMutationType(),
                'subscription' => $this->schema->getSubscriptionType(),
                default => null,
            };

            if ($rootType === null) {
                return [
                    'data' => null,
                    'errors' => [['message' => "Schema does not support {$operation->operation}"]],
                ];
            }

            // Coerce variable values
            $this->coerceVariables($operation);

            // Execute
            try {
                $data = $this->executeSelectionSet(
                    $operation->selectionSet,
                    $rootType,
                    $this->rootValue
                );

                return [
                    'data' => $data,
                    'errors' => empty($this->errors) ? null : $this->errors,
                ];
            } catch (\Throwable $e) {
                $this->errors[] = ['message' => $e->getMessage()];
                return [
                    'data' => null,
                    'errors' => $this->errors,
                ];
            }
        });
    }

    /**
     * Find the operation to execute
     */
    protected function findOperation(DocumentNode $document, ?string $operationName): ?OperationDefinitionNode
    {
        $operations = [];
        foreach ($document->definitions as $definition) {
            if ($definition instanceof OperationDefinitionNode) {
                $operations[] = $definition;
            }
        }

        if (empty($operations)) {
            return null;
        }

        if ($operationName === null) {
            if (count($operations) === 1) {
                return $operations[0];
            }
            return null;
        }

        foreach ($operations as $operation) {
            if ($operation->name === $operationName) {
                return $operation;
            }
        }

        return null;
    }

    /**
     * Coerce variable values
     */
    protected function coerceVariables(OperationDefinitionNode $operation): void
    {
        foreach ($operation->variableDefinitions as $varDef) {
            $varName = $varDef->name;
            
            if (!isset($this->variables[$varName])) {
                if ($varDef->defaultValue !== null) {
                    $this->variables[$varName] = $varDef->defaultValue->getValue();
                }
            }
        }
    }

    /**
     * Execute a selection set
     */
    protected function executeSelectionSet(
        SelectionSetNode $selectionSet,
        ObjectType $parentType,
        mixed $sourceValue
    ): array {
        $result = [];
        $futures = [];

        foreach ($selectionSet->selections as $selection) {
            if ($selection instanceof FieldNode) {
                if (!$this->shouldIncludeNode($selection->directives)) {
                    continue;
                }

                $responseKey = $selection->getResponseKey();
                $futures[$responseKey] = $this->executeField(
                    $parentType,
                    $sourceValue,
                    $selection
                );
            } elseif ($selection instanceof FragmentSpreadNode) {
                if (!$this->shouldIncludeNode($selection->directives)) {
                    continue;
                }

                $fragment = $this->fragments[$selection->name] ?? null;
                if ($fragment === null) {
                    continue;
                }

                if (!$this->doesFragmentTypeApply($fragment->typeCondition, $parentType)) {
                    continue;
                }

                $fragmentResult = $this->executeSelectionSet(
                    $fragment->selectionSet,
                    $parentType,
                    $sourceValue
                );

                foreach ($fragmentResult as $key => $value) {
                    $result[$key] = $value;
                }
            } elseif ($selection instanceof InlineFragmentNode) {
                if (!$this->shouldIncludeNode($selection->directives)) {
                    continue;
                }

                if ($selection->typeCondition !== null && 
                    !$this->doesFragmentTypeApply($selection->typeCondition, $parentType)) {
                    continue;
                }

                $fragmentResult = $this->executeSelectionSet(
                    $selection->selectionSet,
                    $parentType,
                    $sourceValue
                );

                foreach ($fragmentResult as $key => $value) {
                    $result[$key] = $value;
                }
            }
        }

        // Await all field futures
        foreach ($futures as $key => $future) {
            try {
                $result[$key] = $future->await();
            } catch (\Throwable $e) {
                $this->errors[] = [
                    'message' => $e->getMessage(),
                    'path' => [$key],
                ];
                $result[$key] = null;
            }
        }

        return $result;
    }

    /**
     * Execute a single field
     */
    protected function executeField(
        ObjectType $parentType,
        mixed $sourceValue,
        FieldNode $fieldNode
    ): Future {
        return async(function () use ($parentType, $sourceValue, $fieldNode) {
            $fieldName = $fieldNode->name;

            // Handle introspection fields
            if ($fieldName === '__typename') {
                return $parentType->getName();
            }

            if ($fieldName === '__schema' && $parentType === $this->schema->getQueryType()) {
                return $this->introspectSchema();
            }

            if ($fieldName === '__type' && $parentType === $this->schema->getQueryType()) {
                $args = $this->coerceArguments($fieldNode->arguments);
                return $this->introspectType($args['name'] ?? null);
            }

            $fieldDef = $parentType->getField($fieldName);
            if ($fieldDef === null) {
                return null;
            }

            // Coerce arguments
            $args = $this->coerceArguments($fieldNode->arguments, $fieldDef->args);

            // Resolve field value
            $resolver = $fieldDef->getResolver();
            $resolvedValue = null;

            if ($resolver !== null) {
                $resolvedValue = $resolver($sourceValue, $args, $this->context, [
                    'fieldName' => $fieldName,
                    'parentType' => $parentType,
                    'schema' => $this->schema,
                ]);

                // Handle Future/Promise
                if ($resolvedValue instanceof Future) {
                    $resolvedValue = $resolvedValue->await();
                }
            } elseif (is_array($sourceValue) && isset($sourceValue[$fieldName])) {
                $resolvedValue = $sourceValue[$fieldName];
            } elseif (is_object($sourceValue)) {
                if (isset($sourceValue->$fieldName)) {
                    $resolvedValue = $sourceValue->$fieldName;
                } elseif (method_exists($sourceValue, $fieldName)) {
                    $resolvedValue = $sourceValue->$fieldName();
                } elseif (method_exists($sourceValue, 'get' . ucfirst($fieldName))) {
                    $method = 'get' . ucfirst($fieldName);
                    $resolvedValue = $sourceValue->$method();
                }
            }

            // Complete value
            return $this->completeValue(
                $fieldDef->type,
                $fieldNode,
                $resolvedValue
            );
        });
    }

    /**
     * Complete a value based on its type
     */
    protected function completeValue(
        TypeInterface|string $returnType,
        FieldNode $fieldNode,
        mixed $result
    ): mixed {
        // Resolve type reference
        if (is_string($returnType)) {
            $returnType = $this->schema->getType($returnType);
            if ($returnType === null) {
                return null;
            }
        }

        // Handle NonNull
        if ($returnType instanceof NonNullType) {
            $completed = $this->completeValue(
                $returnType->getOfType(),
                $fieldNode,
                $result
            );

            if ($completed === null) {
                throw new \RuntimeException("Cannot return null for non-nullable field");
            }

            return $completed;
        }

        // Handle null
        if ($result === null) {
            return null;
        }

        // Handle List
        if ($returnType instanceof ListType) {
            if (!is_array($result) && !($result instanceof \Traversable)) {
                throw new \RuntimeException("Expected array for list type");
            }

            $completedList = [];
            foreach ($result as $item) {
                $completedList[] = $this->completeValue(
                    $returnType->getOfType(),
                    $fieldNode,
                    $item
                );
            }
            return $completedList;
        }

        // Handle leaf types
        if ($returnType->isLeafType()) {
            return $returnType->serialize($result);
        }

        // Handle object types
        if ($returnType instanceof ObjectType) {
            if ($fieldNode->selectionSet === null) {
                throw new \RuntimeException("Object type requires selection set");
            }

            return $this->executeSelectionSet(
                $fieldNode->selectionSet,
                $returnType,
                $result
            );
        }

        return null;
    }

    /**
     * Coerce arguments
     */
    protected function coerceArguments(array $argumentNodes, array $argDefs = []): array
    {
        $args = [];

        foreach ($argumentNodes as $argNode) {
            $value = $this->valueFromAST($argNode->value);
            $args[$argNode->name] = $value;
        }

        // Apply defaults
        foreach ($argDefs as $name => $argDef) {
            if (!isset($args[$name]) && $argDef->hasDefaultValue) {
                $args[$name] = $argDef->defaultValue;
            }
        }

        return $args;
    }

    /**
     * Get value from AST node
     */
    protected function valueFromAST(ValueNode $valueNode): mixed
    {
        if ($valueNode instanceof VariableNode) {
            return $this->variables[$valueNode->name] ?? null;
        }

        return $valueNode->getValue();
    }

    /**
     * Check if node should be included based on directives
     */
    protected function shouldIncludeNode(array $directives): bool
    {
        foreach ($directives as $directive) {
            if ($directive->name === 'skip') {
                $args = $this->coerceArguments($directive->arguments);
                if ($args['if'] ?? false) {
                    return false;
                }
            }

            if ($directive->name === 'include') {
                $args = $this->coerceArguments($directive->arguments);
                if (!($args['if'] ?? true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Check if fragment type applies
     */
    protected function doesFragmentTypeApply(string $fragmentType, ObjectType $objectType): bool
    {
        if ($fragmentType === $objectType->getName()) {
            return true;
        }

        // Check interfaces
        foreach ($objectType->getInterfaces() as $interface) {
            if (is_string($interface) && $interface === $fragmentType) {
                return true;
            }
            if ($interface instanceof TypeInterface && $interface->getName() === $fragmentType) {
                return true;
            }
        }

        return false;
    }

    /**
     * Introspect schema
     */
    protected function introspectSchema(): array
    {
        return [
            'queryType' => $this->schema->getQueryType() 
                ? ['name' => $this->schema->getQueryType()->getName()] 
                : null,
            'mutationType' => $this->schema->getMutationType() 
                ? ['name' => $this->schema->getMutationType()->getName()] 
                : null,
            'subscriptionType' => $this->schema->getSubscriptionType() 
                ? ['name' => $this->schema->getSubscriptionType()->getName()] 
                : null,
            'types' => array_map(
                fn($t) => ['name' => $t->getName()],
                $this->schema->getTypes()
            ),
        ];
    }

    /**
     * Introspect a type
     */
    protected function introspectType(?string $name): ?array
    {
        if ($name === null) {
            return null;
        }

        $type = $this->schema->getType($name);
        if ($type === null) {
            return null;
        }

        return [
            'name' => $type->getName(),
            'description' => $type->getDescription(),
        ];
    }
}
