<?php

declare(strict_types=1);

namespace HybridPHP\Core\Database\ORM;

use Amp\Future;

/**
 * Validator interface for ORM validation
 */
interface ValidatorInterface
{
    /**
     * Validate a value
     */
    public function validate($value, array $params = []): Future;

    /**
     * Get validation error message
     */
    public function getErrorMessage(): string;
}