<?php

declare(strict_types=1);

namespace HybridPHP\Core\GraphQL\Type;

/**
 * Base interface for all GraphQL types
 */
interface TypeInterface
{
    /**
     * Get the type name
     */
    public function getName(): string;

    /**
     * Get the type description
     */
    public function getDescription(): ?string;

    /**
     * Serialize a value for output
     */
    public function serialize(mixed $value): mixed;

    /**
     * Parse a value from input
     */
    public function parseValue(mixed $value): mixed;

    /**
     * Check if this type is a leaf type (scalar or enum)
     */
    public function isLeafType(): bool;
}
