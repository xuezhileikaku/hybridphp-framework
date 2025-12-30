<?php

declare(strict_types=1);

namespace HybridPHP\Core\Database\ORM\Validators;

use Amp\Future;

use HybridPHP\Core\Database\ORM\ValidatorInterface;

/**
 * String validator
 */
class StringValidator implements ValidatorInterface
{
    private string $errorMessage = 'This field must be a string';

    /**
     * Validate a value
     */
    public function validate($value, array $params = []): Future
    {
        if ($value === null || $value === '') {
            return async(fn() => true); // Allow empty values, use RequiredValidator for required fields
        }

        if (!is_string($value)) {
            return async(fn() => false);
        }

        // Check minimum length
        if (isset($params['min']) && strlen($value) < $params['min']) {
            $this->errorMessage = "This field must be at least {$params['min']} characters long";
            return async(fn() => false);
        }

        // Check maximum length
        if (isset($params['max']) && strlen($value) > $params['max']) {
            $this->errorMessage = "This field must be no more than {$params['max']} characters long";
            return async(fn() => false);
        }

        return async(fn() => true);
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