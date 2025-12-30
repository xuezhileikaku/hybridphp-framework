<?php

declare(strict_types=1);

namespace HybridPHP\Core\GraphQL\Parser;

/**
 * GraphQL Lexer - tokenizes GraphQL query strings
 */
class Lexer
{
    protected string $source;
    protected int $position = 0;
    protected int $line = 1;
    protected int $column = 1;
    protected int $length;

    // Token types
    public const T_EOF = 'EOF';
    public const T_NAME = 'Name';
    public const T_INT = 'Int';
    public const T_FLOAT = 'Float';
    public const T_STRING = 'String';
    public const T_BLOCK_STRING = 'BlockString';
    public const T_BANG = '!';
    public const T_DOLLAR = '$';
    public const T_AMP = '&';
    public const T_PAREN_L = '(';
    public const T_PAREN_R = ')';
    public const T_SPREAD = '...';
    public const T_COLON = ':';
    public const T_EQUALS = '=';
    public const T_AT = '@';
    public const T_BRACKET_L = '[';
    public const T_BRACKET_R = ']';
    public const T_BRACE_L = '{';
    public const T_PIPE = '|';
    public const T_BRACE_R = '}';

    public function __construct(string $source)
    {
        $this->source = $source;
        $this->length = strlen($source);
    }

    /**
     * Get all tokens from the source
     */
    public function tokenize(): array
    {
        $tokens = [];
        while (($token = $this->nextToken()) !== null) {
            $tokens[] = $token;
            if ($token['type'] === self::T_EOF) {
                break;
            }
        }
        return $tokens;
    }

    /**
     * Get the next token
     */
    public function nextToken(): ?array
    {
        $this->skipWhitespaceAndComments();

        if ($this->position >= $this->length) {
            return $this->createToken(self::T_EOF, '');
        }

        $char = $this->source[$this->position];

        // Single character tokens
        $singleCharTokens = [
            '!' => self::T_BANG,
            '$' => self::T_DOLLAR,
            '&' => self::T_AMP,
            '(' => self::T_PAREN_L,
            ')' => self::T_PAREN_R,
            ':' => self::T_COLON,
            '=' => self::T_EQUALS,
            '@' => self::T_AT,
            '[' => self::T_BRACKET_L,
            ']' => self::T_BRACKET_R,
            '{' => self::T_BRACE_L,
            '}' => self::T_BRACE_R,
            '|' => self::T_PIPE,
        ];

        if (isset($singleCharTokens[$char])) {
            $this->advance();
            return $this->createToken($singleCharTokens[$char], $char);
        }

        // Spread operator
        if ($char === '.' && $this->peek(1) === '.' && $this->peek(2) === '.') {
            $this->advance(3);
            return $this->createToken(self::T_SPREAD, '...');
        }

        // String
        if ($char === '"') {
            if ($this->peek(1) === '"' && $this->peek(2) === '"') {
                return $this->readBlockString();
            }
            return $this->readString();
        }

        // Number
        if ($char === '-' || ctype_digit($char)) {
            return $this->readNumber();
        }

        // Name
        if ($char === '_' || ctype_alpha($char)) {
            return $this->readName();
        }

        throw new SyntaxError("Unexpected character '{$char}'", $this->line, $this->column);
    }

    /**
     * Read a name token
     */
    protected function readName(): array
    {
        $start = $this->position;
        while ($this->position < $this->length) {
            $char = $this->source[$this->position];
            if ($char === '_' || ctype_alnum($char)) {
                $this->advance();
            } else {
                break;
            }
        }
        $value = substr($this->source, $start, $this->position - $start);
        return $this->createToken(self::T_NAME, $value);
    }

    /**
     * Read a number token
     */
    protected function readNumber(): array
    {
        $start = $this->position;
        $isFloat = false;

        if ($this->current() === '-') {
            $this->advance();
        }

        if ($this->current() === '0') {
            $this->advance();
        } else {
            $this->readDigits();
        }

        if ($this->current() === '.') {
            $isFloat = true;
            $this->advance();
            $this->readDigits();
        }

        if ($this->current() === 'e' || $this->current() === 'E') {
            $isFloat = true;
            $this->advance();
            if ($this->current() === '+' || $this->current() === '-') {
                $this->advance();
            }
            $this->readDigits();
        }

        $value = substr($this->source, $start, $this->position - $start);
        return $this->createToken($isFloat ? self::T_FLOAT : self::T_INT, $value);
    }

    /**
     * Read digits
     */
    protected function readDigits(): void
    {
        if (!ctype_digit($this->current() ?? '')) {
            throw new SyntaxError("Expected digit", $this->line, $this->column);
        }
        while (ctype_digit($this->current() ?? '')) {
            $this->advance();
        }
    }

    /**
     * Read a string token
     */
    protected function readString(): array
    {
        $this->advance(); // Skip opening quote
        $value = '';

        while ($this->position < $this->length) {
            $char = $this->source[$this->position];

            if ($char === '"') {
                $this->advance();
                return $this->createToken(self::T_STRING, $value);
            }

            if ($char === '\\') {
                $this->advance();
                $value .= $this->readEscapedChar();
            } else {
                $value .= $char;
                $this->advance();
            }
        }

        throw new SyntaxError("Unterminated string", $this->line, $this->column);
    }

    /**
     * Read a block string token
     */
    protected function readBlockString(): array
    {
        $this->advance(3); // Skip opening """
        $value = '';

        while ($this->position < $this->length) {
            if ($this->current() === '"' && $this->peek(1) === '"' && $this->peek(2) === '"') {
                $this->advance(3);
                return $this->createToken(self::T_BLOCK_STRING, $this->dedentBlockString($value));
            }
            $value .= $this->source[$this->position];
            $this->advance();
        }

        throw new SyntaxError("Unterminated block string", $this->line, $this->column);
    }

    /**
     * Read an escaped character
     */
    protected function readEscapedChar(): string
    {
        $char = $this->source[$this->position] ?? '';
        $this->advance();

        return match ($char) {
            '"' => '"',
            '\\' => '\\',
            '/' => '/',
            'b' => "\b",
            'f' => "\f",
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            'u' => $this->readUnicodeChar(),
            default => throw new SyntaxError("Invalid escape sequence: \\{$char}", $this->line, $this->column),
        };
    }

    /**
     * Read a unicode escape sequence
     */
    protected function readUnicodeChar(): string
    {
        $hex = substr($this->source, $this->position, 4);
        $this->advance(4);
        return mb_chr(hexdec($hex));
    }

    /**
     * Dedent a block string
     */
    protected function dedentBlockString(string $value): string
    {
        $lines = explode("\n", $value);
        $commonIndent = null;

        foreach ($lines as $i => $line) {
            if ($i === 0) continue;
            $indent = strlen($line) - strlen(ltrim($line));
            if (trim($line) !== '' && ($commonIndent === null || $indent < $commonIndent)) {
                $commonIndent = $indent;
            }
        }

        if ($commonIndent !== null && $commonIndent > 0) {
            foreach ($lines as $i => &$line) {
                if ($i > 0 && strlen($line) >= $commonIndent) {
                    $line = substr($line, $commonIndent);
                }
            }
        }

        // Remove leading/trailing blank lines
        while (count($lines) > 0 && trim($lines[0]) === '') {
            array_shift($lines);
        }
        while (count($lines) > 0 && trim($lines[count($lines) - 1]) === '') {
            array_pop($lines);
        }

        return implode("\n", $lines);
    }

    /**
     * Skip whitespace and comments
     */
    protected function skipWhitespaceAndComments(): void
    {
        while ($this->position < $this->length) {
            $char = $this->source[$this->position];

            if ($char === ' ' || $char === "\t" || $char === ',') {
                $this->advance();
            } elseif ($char === "\n" || $char === "\r") {
                $this->advanceLine();
            } elseif ($char === '#') {
                $this->skipComment();
            } else {
                break;
            }
        }
    }

    /**
     * Skip a comment
     */
    protected function skipComment(): void
    {
        while ($this->position < $this->length) {
            $char = $this->source[$this->position];
            if ($char === "\n" || $char === "\r") {
                break;
            }
            $this->advance();
        }
    }

    /**
     * Get current character
     */
    protected function current(): ?string
    {
        return $this->source[$this->position] ?? null;
    }

    /**
     * Peek ahead
     */
    protected function peek(int $offset): ?string
    {
        $pos = $this->position + $offset;
        return $this->source[$pos] ?? null;
    }

    /**
     * Advance position
     */
    protected function advance(int $count = 1): void
    {
        $this->position += $count;
        $this->column += $count;
    }

    /**
     * Advance to next line
     */
    protected function advanceLine(): void
    {
        $char = $this->source[$this->position];
        $this->position++;
        if ($char === "\r" && ($this->source[$this->position] ?? '') === "\n") {
            $this->position++;
        }
        $this->line++;
        $this->column = 1;
    }

    /**
     * Create a token
     */
    protected function createToken(string $type, string $value): array
    {
        return [
            'type' => $type,
            'value' => $value,
            'line' => $this->line,
            'column' => $this->column,
        ];
    }
}

/**
 * Syntax error exception
 */
class SyntaxError extends \Exception
{
    public int $line;
    public int $column;

    public function __construct(string $message, int $line, int $column)
    {
        $this->line = $line;
        $this->column = $column;
        parent::__construct("Syntax Error: {$message} at line {$line}, column {$column}");
    }
}
