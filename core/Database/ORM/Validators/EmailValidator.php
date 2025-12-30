<?php

declare(strict_types=1);

namespace HybridPHP\Core\Database\ORM\Validators;

use Amp\Future;

use HybridPHP\Core\Database\ORM\ValidatorInterface;

/**
 * Email validator
 */
class EmailValidator implements ValidatorInterface
{
    private string $errorMessage = 'This field must be a valid email address';

    /**
     * Validate a value
     */
    public function validate($value, array $params = []): Future
    {
        if ($value === null || $value === '') {
            return async(fn() => true); // Allow empty values, use RequiredValidator for required fields
        }

        $isValid = filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
        return async(fn() => $isValid);
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