<?php

namespace HybridPHP\Core\Http;

/**
 * Request validator with Yii2-style validation rules and chaining support
 */
class RequestValidator
{
    private Request $request;
    private array $errors = [];
    private array $customRules = [];
    
    // Built-in validation rules
    private array $builtInRules = [
        'required', 'string', 'integer', 'numeric', 'email', 'url', 'boolean',
        'min', 'max', 'length', 'in', 'regex', 'date', 'file', 'image'
    ];

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Validate request data against rules
     */
    public function validate(array $rules): bool
    {
        $this->errors = [];
        
        foreach ($rules as $field => $fieldRules) {
            $this->validateField($field, $fieldRules);
        }
        
        return empty($this->errors);
    }

    /**
     * Validate a single field
     */
    private function validateField(string $field, $rules): void
    {
        if (is_string($rules)) {
            $rules = explode('|', $rules);
        }
        
        if (!is_array($rules)) {
            $rules = [$rules];
        }
        
        $value = $this->getFieldValue($field);
        
        foreach ($rules as $rule) {
            if (!$this->validateRule($field, $value, $rule)) {
                break; // Stop on first error for this field
            }
        }
    }

    /**
     * Validate a single rule
     */
    private function validateRule(string $field, $value, $rule): bool
    {
        if (is_callable($rule)) {
            return $this->validateCustomRule($field, $value, $rule);
        }
        
        if (is_string($rule)) {
            return $this->validateStringRule($field, $value, $rule);
        }
        
        if (is_array($rule) && isset($rule[0])) {
            $ruleName = $rule[0];
            $params = array_slice($rule, 1);
            return $this->validateRuleWithParams($field, $value, $ruleName, $params);
        }
        
        return true;
    }

    /**
     * Validate custom callable rule
     */
    private function validateCustomRule(string $field, $value, callable $rule): bool
    {
        try {
            $result = $rule($value, $this->request);
            
            if ($result === true) {
                return true;
            }
            
            if ($result === false) {
                $this->addError($field, "Validation failed for field {$field}");
                return false;
            }
            
            if (is_string($result)) {
                $this->addError($field, $result);
                return false;
            }
            
            return true;
        } catch (\Exception $e) {
            $this->addError($field, "Validation error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Validate string rule (e.g., "required", "email")
     */
    private function validateStringRule(string $field, $value, string $rule): bool
    {
        // Parse rule with parameters (e.g., "min:5", "in:1,2,3")
        if (strpos($rule, ':') !== false) {
            [$ruleName, $paramString] = explode(':', $rule, 2);
            $params = explode(',', $paramString);
            return $this->validateRuleWithParams($field, $value, $ruleName, $params);
        }
        
        return $this->validateRuleWithParams($field, $value, $rule, []);
    }

    /**
     * Validate rule with parameters
     */
    private function validateRuleWithParams(string $field, $value, string $ruleName, array $params): bool
    {
        // Check for custom rule first
        if (isset($this->customRules[$ruleName])) {
            return $this->validateCustomRule($field, $value, $this->customRules[$ruleName]);
        }
        
        // Built-in rules
        switch ($ruleName) {
            case 'required':
                return $this->validateRequired($field, $value);
                
            case 'string':
                return $this->validateString($field, $value);
                
            case 'integer':
            case 'int':
                return $this->validateInteger($field, $value);
                
            case 'numeric':
                return $this->validateNumeric($field, $value);
                
            case 'email':
                return $this->validateEmail($field, $value);
                
            case 'url':
                return $this->validateUrl($field, $value);
                
            case 'boolean':
            case 'bool':
                return $this->validateBoolean($field, $value);
                
            case 'min':
                return $this->validateMin($field, $value, $params[0] ?? 0);
                
            case 'max':
                return $this->validateMax($field, $value, $params[0] ?? PHP_INT_MAX);
                
            case 'length':
                $min = $params[0] ?? 0;
                $max = $params[1] ?? PHP_INT_MAX;
                return $this->validateLength($field, $value, $min, $max);
                
            case 'in':
                return $this->validateIn($field, $value, $params);
                
            case 'regex':
                return $this->validateRegex($field, $value, $params[0] ?? '');
                
            case 'date':
                return $this->validateDate($field, $value, $params[0] ?? 'Y-m-d');
                
            case 'file':
                return $this->validateFile($field, $value);
                
            case 'image':
                return $this->validateImage($field, $value);
                
            default:
                // Unknown rule, skip
                return true;
        }
    }

    // Built-in validation methods
    
    private function validateRequired(string $field, $value): bool
    {
        if ($value === null || $value === '' || (is_array($value) && empty($value))) {
            $this->addError($field, "The {$field} field is required");
            return false;
        }
        return true;
    }

    private function validateString(string $field, $value): bool
    {
        if ($value !== null && !is_string($value)) {
            $this->addError($field, "The {$field} field must be a string");
            return false;
        }
        return true;
    }

    private function validateInteger(string $field, $value): bool
    {
        if ($value !== null && !filter_var($value, FILTER_VALIDATE_INT)) {
            $this->addError($field, "The {$field} field must be an integer");
            return false;
        }
        return true;
    }

    private function validateNumeric(string $field, $value): bool
    {
        if ($value !== null && !is_numeric($value)) {
            $this->addError($field, "The {$field} field must be numeric");
            return false;
        }
        return true;
    }

    private function validateEmail(string $field, $value): bool
    {
        if ($value !== null && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->addError($field, "The {$field} field must be a valid email address");
            return false;
        }
        return true;
    }

    private function validateUrl(string $field, $value): bool
    {
        if ($value !== null && !filter_var($value, FILTER_VALIDATE_URL)) {
            $this->addError($field, "The {$field} field must be a valid URL");
            return false;
        }
        return true;
    }

    private function validateBoolean(string $field, $value): bool
    {
        if ($value !== null && !in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false'], true)) {
            $this->addError($field, "The {$field} field must be a boolean");
            return false;
        }
        return true;
    }

    private function validateMin(string $field, $value, $min): bool
    {
        if ($value === null) {
            return true;
        }
        
        if (is_numeric($value)) {
            if ($value < $min) {
                $this->addError($field, "The {$field} field must be at least {$min}");
                return false;
            }
        } elseif (is_string($value)) {
            if (strlen($value) < $min) {
                $this->addError($field, "The {$field} field must be at least {$min} characters");
                return false;
            }
        }
        
        return true;
    }

    private function validateMax(string $field, $value, $max): bool
    {
        if ($value === null) {
            return true;
        }
        
        if (is_numeric($value)) {
            if ($value > $max) {
                $this->addError($field, "The {$field} field must not exceed {$max}");
                return false;
            }
        } elseif (is_string($value)) {
            if (strlen($value) > $max) {
                $this->addError($field, "The {$field} field must not exceed {$max} characters");
                return false;
            }
        }
        
        return true;
    }

    private function validateLength(string $field, $value, $min, $max): bool
    {
        if ($value === null) {
            return true;
        }
        
        $length = is_string($value) ? strlen($value) : 0;
        
        if ($length < $min || $length > $max) {
            $this->addError($field, "The {$field} field must be between {$min} and {$max} characters");
            return false;
        }
        
        return true;
    }

    private function validateIn(string $field, $value, array $allowed): bool
    {
        if ($value !== null && !in_array($value, $allowed, true)) {
            $allowedStr = implode(', ', $allowed);
            $this->addError($field, "The {$field} field must be one of: {$allowedStr}");
            return false;
        }
        return true;
    }

    private function validateRegex(string $field, $value, string $pattern): bool
    {
        if ($value !== null && !preg_match($pattern, $value)) {
            $this->addError($field, "The {$field} field format is invalid");
            return false;
        }
        return true;
    }

    private function validateDate(string $field, $value, string $format): bool
    {
        if ($value === null) {
            return true;
        }
        
        $date = \DateTime::createFromFormat($format, $value);
        if (!$date || $date->format($format) !== $value) {
            $this->addError($field, "The {$field} field must be a valid date in format {$format}");
            return false;
        }
        
        return true;
    }

    private function validateFile(string $field, $value): bool
    {
        // Check if it's an uploaded file
        $uploadedFiles = $this->request->getUploadedFiles();
        if (!isset($uploadedFiles[$field])) {
            $this->addError($field, "The {$field} field must be a file");
            return false;
        }
        
        $file = $uploadedFiles[$field];
        if ($file->getError() !== UPLOAD_ERR_OK) {
            $this->addError($field, "File upload failed for {$field}");
            return false;
        }
        
        return true;
    }

    private function validateImage(string $field, $value): bool
    {
        if (!$this->validateFile($field, $value)) {
            return false;
        }
        
        $uploadedFiles = $this->request->getUploadedFiles();
        $file = $uploadedFiles[$field];
        
        $mimeType = $file->getClientMediaType();
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        
        if (!in_array($mimeType, $allowedTypes)) {
            $this->addError($field, "The {$field} field must be an image (JPEG, PNG, GIF, WebP)");
            return false;
        }
        
        return true;
    }

    /**
     * Get field value from request
     */
    private function getFieldValue(string $field)
    {
        return $this->request->get($field);
    }

    /**
     * Add validation error
     */
    private function addError(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }
        $this->errors[$field][] = $message;
    }

    /**
     * Get all validation errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get errors for a specific field
     */
    public function getFieldErrors(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    /**
     * Check if field has errors
     */
    public function hasFieldError(string $field): bool
    {
        return isset($this->errors[$field]) && !empty($this->errors[$field]);
    }

    /**
     * Add custom validation rule
     */
    public function addRule(string $name, callable $rule): self
    {
        $this->customRules[$name] = $rule;
        return $this;
    }

    /**
     * Add multiple custom rules
     */
    public function addRules(array $rules): self
    {
        foreach ($rules as $name => $rule) {
            $this->addRule($name, $rule);
        }
        return $this;
    }

    /**
     * Create a fluent validation chain
     */
    public static function make(Request $request): ValidationChain
    {
        return new ValidationChain($request);
    }
}

/**
 * Fluent validation chain for Yii2-style chaining
 */
class ValidationChain
{
    private Request $request;
    private RequestValidator $validator;
    private array $rules = [];

    public function __construct(Request $request)
    {
        $this->request = $request;
        $this->validator = new RequestValidator($request);
    }

    /**
     * Create a fluent validation chain
     */
    public static function make(Request $request): ValidationChain
    {
        return new ValidationChain($request);
    }

    /**
     * Add validation rule for a field
     */
    public function field(string $field): FieldValidator
    {
        return new FieldValidator($this, $field);
    }

    /**
     * Add rule for field
     */
    public function addFieldRule(string $field, $rule): self
    {
        if (!isset($this->rules[$field])) {
            $this->rules[$field] = [];
        }
        $this->rules[$field][] = $rule;
        return $this;
    }

    /**
     * Add custom validation rule
     */
    public function rule(string $name, callable $rule): self
    {
        $this->validator->addRule($name, $rule);
        return $this;
    }

    /**
     * Validate all rules
     */
    public function validate(): bool
    {
        return $this->validator->validate($this->rules);
    }

    /**
     * Get validation errors
     */
    public function getErrors(): array
    {
        return $this->validator->getErrors();
    }

    /**
     * Get validator instance
     */
    public function getValidator(): RequestValidator
    {
        return $this->validator;
    }
}

/**
 * Field validator for fluent chaining
 */
class FieldValidator
{
    private ValidationChain $chain;
    private string $field;

    public function __construct(ValidationChain $chain, string $field)
    {
        $this->chain = $chain;
        $this->field = $field;
    }

    public function required(): self
    {
        $this->chain->addFieldRule($this->field, 'required');
        return $this;
    }

    public function string(): self
    {
        $this->chain->addFieldRule($this->field, 'string');
        return $this;
    }

    public function integer(): self
    {
        $this->chain->addFieldRule($this->field, 'integer');
        return $this;
    }

    public function numeric(): self
    {
        $this->chain->addFieldRule($this->field, 'numeric');
        return $this;
    }

    public function email(): self
    {
        $this->chain->addFieldRule($this->field, 'email');
        return $this;
    }

    public function url(): self
    {
        $this->chain->addFieldRule($this->field, 'url');
        return $this;
    }

    public function boolean(): self
    {
        $this->chain->addFieldRule($this->field, 'boolean');
        return $this;
    }

    public function min($value): self
    {
        $this->chain->addFieldRule($this->field, "min:{$value}");
        return $this;
    }

    public function max($value): self
    {
        $this->chain->addFieldRule($this->field, "max:{$value}");
        return $this;
    }

    public function length(int $min, int $max = null): self
    {
        if ($max === null) {
            $this->chain->addFieldRule($this->field, "length:{$min}");
        } else {
            $this->chain->addFieldRule($this->field, "length:{$min},{$max}");
        }
        return $this;
    }

    public function in(array $values): self
    {
        $valueStr = implode(',', $values);
        $this->chain->addFieldRule($this->field, "in:{$valueStr}");
        return $this;
    }

    public function regex(string $pattern): self
    {
        $this->chain->addFieldRule($this->field, "regex:{$pattern}");
        return $this;
    }

    public function date(string $format = 'Y-m-d'): self
    {
        $this->chain->addFieldRule($this->field, "date:{$format}");
        return $this;
    }

    public function file(): self
    {
        $this->chain->addFieldRule($this->field, 'file');
        return $this;
    }

    public function image(): self
    {
        $this->chain->addFieldRule($this->field, 'image');
        return $this;
    }

    public function custom(callable $rule): self
    {
        $this->chain->addFieldRule($this->field, $rule);
        return $this;
    }

    public function field(string $field): FieldValidator
    {
        return $this->chain->field($field);
    }

    public function validate(): bool
    {
        return $this->chain->validate();
    }

    public function getErrors(): array
    {
        return $this->chain->getErrors();
    }
}