<?php

declare(strict_types=1);

namespace HybridPHP\Core\GraphQL\Parser;

/**
 * GraphQL Parser - parses GraphQL query strings into AST
 */
class Parser
{
    protected Lexer $lexer;
    protected array $tokens = [];
    protected int $position = 0;

    public function __construct(string $source)
    {
        $this->lexer = new Lexer($source);
        $this->tokens = $this->lexer->tokenize();
    }

    /**
     * Parse the document
     */
    public function parse(): DocumentNode
    {
        $definitions = [];

        while (!$this->isEof()) {
            $definitions[] = $this->parseDefinition();
        }

        return new DocumentNode($definitions);
    }

    /**
     * Parse a definition
     */
    protected function parseDefinition(): DefinitionNode
    {
        if ($this->peek(Lexer::T_NAME)) {
            $name = $this->current()['value'];

            switch ($name) {
                case 'query':
                case 'mutation':
                case 'subscription':
                    return $this->parseOperationDefinition();
                case 'fragment':
                    return $this->parseFragmentDefinition();
            }
        }

        if ($this->peek(Lexer::T_BRACE_L)) {
            return $this->parseOperationDefinition();
        }

        throw $this->unexpected();
    }

    /**
     * Parse an operation definition
     */
    protected function parseOperationDefinition(): OperationDefinitionNode
    {
        if ($this->peek(Lexer::T_BRACE_L)) {
            return new OperationDefinitionNode(
                'query',
                null,
                [],
                [],
                $this->parseSelectionSet()
            );
        }

        $operation = $this->expectName();
        $name = null;
        $variables = [];
        $directives = [];

        if ($this->peek(Lexer::T_NAME)) {
            $name = $this->expectName();
        }

        if ($this->peek(Lexer::T_PAREN_L)) {
            $variables = $this->parseVariableDefinitions();
        }

        if ($this->peek(Lexer::T_AT)) {
            $directives = $this->parseDirectives();
        }

        return new OperationDefinitionNode(
            $operation,
            $name,
            $variables,
            $directives,
            $this->parseSelectionSet()
        );
    }

    /**
     * Parse variable definitions
     */
    protected function parseVariableDefinitions(): array
    {
        $this->expect(Lexer::T_PAREN_L);
        $variables = [];

        while (!$this->peek(Lexer::T_PAREN_R)) {
            $variables[] = $this->parseVariableDefinition();
        }

        $this->expect(Lexer::T_PAREN_R);
        return $variables;
    }

    /**
     * Parse a variable definition
     */
    protected function parseVariableDefinition(): VariableDefinitionNode
    {
        $this->expect(Lexer::T_DOLLAR);
        $name = $this->expectName();
        $this->expect(Lexer::T_COLON);
        $type = $this->parseTypeReference();
        $defaultValue = null;

        if ($this->skip(Lexer::T_EQUALS)) {
            $defaultValue = $this->parseValue(true);
        }

        return new VariableDefinitionNode($name, $type, $defaultValue);
    }

    /**
     * Parse a selection set
     */
    protected function parseSelectionSet(): SelectionSetNode
    {
        $this->expect(Lexer::T_BRACE_L);
        $selections = [];

        while (!$this->peek(Lexer::T_BRACE_R)) {
            $selections[] = $this->parseSelection();
        }

        $this->expect(Lexer::T_BRACE_R);
        return new SelectionSetNode($selections);
    }

    /**
     * Parse a selection
     */
    protected function parseSelection(): SelectionNode
    {
        if ($this->peek(Lexer::T_SPREAD)) {
            return $this->parseFragment();
        }
        return $this->parseField();
    }

    /**
     * Parse a field
     */
    protected function parseField(): FieldNode
    {
        $nameOrAlias = $this->expectName();
        $alias = null;
        $name = $nameOrAlias;

        if ($this->skip(Lexer::T_COLON)) {
            $alias = $nameOrAlias;
            $name = $this->expectName();
        }

        $arguments = [];
        if ($this->peek(Lexer::T_PAREN_L)) {
            $arguments = $this->parseArguments();
        }

        $directives = [];
        if ($this->peek(Lexer::T_AT)) {
            $directives = $this->parseDirectives();
        }

        $selectionSet = null;
        if ($this->peek(Lexer::T_BRACE_L)) {
            $selectionSet = $this->parseSelectionSet();
        }

        return new FieldNode($name, $alias, $arguments, $directives, $selectionSet);
    }

    /**
     * Parse arguments
     */
    protected function parseArguments(): array
    {
        $this->expect(Lexer::T_PAREN_L);
        $arguments = [];

        while (!$this->peek(Lexer::T_PAREN_R)) {
            $arguments[] = $this->parseArgument();
        }

        $this->expect(Lexer::T_PAREN_R);
        return $arguments;
    }

    /**
     * Parse an argument
     */
    protected function parseArgument(): ArgumentNode
    {
        $name = $this->expectName();
        $this->expect(Lexer::T_COLON);
        $value = $this->parseValue(false);

        return new ArgumentNode($name, $value);
    }

    /**
     * Parse a fragment
     */
    protected function parseFragment(): SelectionNode
    {
        $this->expect(Lexer::T_SPREAD);

        if ($this->peek(Lexer::T_NAME) && $this->current()['value'] !== 'on') {
            return new FragmentSpreadNode(
                $this->expectName(),
                $this->peek(Lexer::T_AT) ? $this->parseDirectives() : []
            );
        }

        $typeCondition = null;
        if ($this->skip(Lexer::T_NAME) && $this->tokens[$this->position - 1]['value'] === 'on') {
            // We already consumed 'on', now get the type name
            $typeCondition = $this->expectName();
        }

        return new InlineFragmentNode(
            $typeCondition,
            $this->peek(Lexer::T_AT) ? $this->parseDirectives() : [],
            $this->parseSelectionSet()
        );
    }

    /**
     * Parse a fragment definition
     */
    protected function parseFragmentDefinition(): FragmentDefinitionNode
    {
        $this->expectKeyword('fragment');
        $name = $this->expectName();
        $this->expectKeyword('on');
        $typeCondition = $this->expectName();

        $directives = [];
        if ($this->peek(Lexer::T_AT)) {
            $directives = $this->parseDirectives();
        }

        return new FragmentDefinitionNode(
            $name,
            $typeCondition,
            $directives,
            $this->parseSelectionSet()
        );
    }

    /**
     * Parse directives
     */
    protected function parseDirectives(): array
    {
        $directives = [];
        while ($this->peek(Lexer::T_AT)) {
            $directives[] = $this->parseDirective();
        }
        return $directives;
    }

    /**
     * Parse a directive
     */
    protected function parseDirective(): DirectiveNode
    {
        $this->expect(Lexer::T_AT);
        $name = $this->expectName();
        $arguments = [];

        if ($this->peek(Lexer::T_PAREN_L)) {
            $arguments = $this->parseArguments();
        }

        return new DirectiveNode($name, $arguments);
    }

    /**
     * Parse a value
     */
    protected function parseValue(bool $isConst): ValueNode
    {
        $token = $this->current();

        switch ($token['type']) {
            case Lexer::T_BRACKET_L:
                return $this->parseList($isConst);
            case Lexer::T_BRACE_L:
                return $this->parseObject($isConst);
            case Lexer::T_INT:
                $this->advance();
                return new IntValueNode((int) $token['value']);
            case Lexer::T_FLOAT:
                $this->advance();
                return new FloatValueNode((float) $token['value']);
            case Lexer::T_STRING:
            case Lexer::T_BLOCK_STRING:
                $this->advance();
                return new StringValueNode($token['value']);
            case Lexer::T_NAME:
                $this->advance();
                if ($token['value'] === 'true') {
                    return new BooleanValueNode(true);
                }
                if ($token['value'] === 'false') {
                    return new BooleanValueNode(false);
                }
                if ($token['value'] === 'null') {
                    return new NullValueNode();
                }
                return new EnumValueNode($token['value']);
            case Lexer::T_DOLLAR:
                if (!$isConst) {
                    return $this->parseVariable();
                }
                break;
        }

        throw $this->unexpected();
    }

    /**
     * Parse a variable
     */
    protected function parseVariable(): VariableNode
    {
        $this->expect(Lexer::T_DOLLAR);
        return new VariableNode($this->expectName());
    }

    /**
     * Parse a list value
     */
    protected function parseList(bool $isConst): ListValueNode
    {
        $this->expect(Lexer::T_BRACKET_L);
        $values = [];

        while (!$this->peek(Lexer::T_BRACKET_R)) {
            $values[] = $this->parseValue($isConst);
        }

        $this->expect(Lexer::T_BRACKET_R);
        return new ListValueNode($values);
    }

    /**
     * Parse an object value
     */
    protected function parseObject(bool $isConst): ObjectValueNode
    {
        $this->expect(Lexer::T_BRACE_L);
        $fields = [];

        while (!$this->peek(Lexer::T_BRACE_R)) {
            $name = $this->expectName();
            $this->expect(Lexer::T_COLON);
            $value = $this->parseValue($isConst);
            $fields[$name] = $value;
        }

        $this->expect(Lexer::T_BRACE_R);
        return new ObjectValueNode($fields);
    }

    /**
     * Parse a type reference
     */
    protected function parseTypeReference(): TypeNode
    {
        $type = null;

        if ($this->peek(Lexer::T_BRACKET_L)) {
            $this->advance();
            $type = new ListTypeNode($this->parseTypeReference());
            $this->expect(Lexer::T_BRACKET_R);
        } else {
            $type = new NamedTypeNode($this->expectName());
        }

        if ($this->skip(Lexer::T_BANG)) {
            return new NonNullTypeNode($type);
        }

        return $type;
    }

    // Helper methods

    protected function current(): array
    {
        return $this->tokens[$this->position] ?? ['type' => Lexer::T_EOF, 'value' => ''];
    }

    protected function peek(string $type): bool
    {
        return $this->current()['type'] === $type;
    }

    protected function skip(string $type): bool
    {
        if ($this->peek($type)) {
            $this->advance();
            return true;
        }
        return false;
    }

    protected function expect(string $type): array
    {
        $token = $this->current();
        if ($token['type'] !== $type) {
            throw new SyntaxError(
                "Expected {$type}, got {$token['type']}",
                $token['line'] ?? 1,
                $token['column'] ?? 1
            );
        }
        $this->advance();
        return $token;
    }

    protected function expectName(): string
    {
        return $this->expect(Lexer::T_NAME)['value'];
    }

    protected function expectKeyword(string $keyword): void
    {
        $token = $this->expect(Lexer::T_NAME);
        if ($token['value'] !== $keyword) {
            throw new SyntaxError(
                "Expected '{$keyword}', got '{$token['value']}'",
                $token['line'],
                $token['column']
            );
        }
    }

    protected function advance(): void
    {
        $this->position++;
    }

    protected function isEof(): bool
    {
        return $this->current()['type'] === Lexer::T_EOF;
    }

    protected function unexpected(): SyntaxError
    {
        $token = $this->current();
        return new SyntaxError(
            "Unexpected token: {$token['type']}",
            $token['line'] ?? 1,
            $token['column'] ?? 1
        );
    }
}
