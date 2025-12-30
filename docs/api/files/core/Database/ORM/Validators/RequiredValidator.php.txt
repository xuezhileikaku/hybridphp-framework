<?php

declare(strict_types=1);

namespace HybridPHP\Core\Database\ORM\Validators;

use Amp\Future;

use HybridPHP\Core\Database\ORM\ValidatorInterface;

/**
 * Required field validator
 */
class RequiredValidator implements ValidatorInterface
{
    private string $errorMessage = 'This field is required';

    /**
     * Validate a value
     */
    public function validate($value, array $params = []): Future
    {
        $isEmpty = $value === null || $value === '' || (is_array($value) && empty($value));
        return async(fn() => !$isEmpty);
    }

    /**
     * Get validation error message
     */
    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    /**
     * Set custom error message
     */
    public function setErrorMessage(string $message): self
    {
        $this->errorMessage = $message;
        return $this;
    }
}