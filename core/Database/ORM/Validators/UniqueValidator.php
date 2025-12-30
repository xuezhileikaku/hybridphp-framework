<?php

declare(strict_types=1);

namespace HybridPHP\Core\Database\ORM\Validators;

use Amp\Future;
use HybridPHP\Core\Database\ORM\ValidatorInterface;
use HybridPHP\Core\Database\ORM\ActiveRecordInterface;
use function Amp\async;

/**
 * Unique field validator
 */
class UniqueValidator implements ValidatorInterface
{
    private string $errorMessage = 'This field must be unique';
    private ActiveRecordInterface $model;
    private string $attribute;

    public function __construct(ActiveRecordInterface $model, string $attribute)
    {
        $this->model = $model;
        $this->attribute = $attribute;
    }

    /**
     * Validate a value
     */
    public function validate($value, array $params = []): Future
    {
        return async(function () use ($value, $params) {
            if ($value === null || $value === '') {
                return true;
            }

            $modelClass = get_class($this->model);
            $condition = [$this->attribute => $value];

            if (!$this->model->isNewRecord()) {
                $primaryKey = $modelClass::primaryKey();
                $condition[$primaryKey . ' !='] = $this->model->getPrimaryKey();
            }

            $count = $modelClass::count($condition)->await();
            return $count === 0;
        });
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