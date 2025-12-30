<?php

declare(strict_types=1);

namespace HybridPHP\Core\Security;

/**
 * Data masking and anonymization service
 */
class DataMasking
{
    private array $maskingRules = [];

    public function __construct()
    {
        $this->initializeDefaultRules();
    }

    /**
     * Initialize default masking rules
     */
    private function initializeDefaultRules(): void
    {
        $this->maskingRules = [
            'email' => function (string $value): string {
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return $this->maskString($value);
                }
                
                $parts = explode('@', $value);
                $username = $parts[0];
                $domain = $parts[1];
                
                $maskedUsername = $this->maskString($username, 2, 1);
                return $maskedUsername . '@' . $domain;
            },
            
            'phone' => function (string $value): string {
                $cleaned = preg_replace('/[^0-9]/', '', $value);
                if (strlen($cleaned) < 4) {
                    return str_repeat('*', strlen($value));
                }
                
                $visible = substr($cleaned, -4);
                $masked = str_repeat('*', strlen($cleaned) - 4);
                return $masked . $visible;
            },
            
            'credit_card' => function (string $value): string {
                $cleaned = preg_replace('/[^0-9]/', '', $value);
                if (strlen($cleaned) < 4) {
                    return str_repeat('*', strlen($value));
                }
                
                $visible = substr($cleaned, -4);
                $masked = str_repeat('*', strlen($cleaned) - 4);
                return $masked . $visible;
            },
            
            'ssn' => function (string $value): string {
                $cleaned = preg_replace('/[^0-9]/', '', $value);
                if (strlen($cleaned) !== 9) {
                    return str_repeat('*', strlen($value));
                }
                
                return '***-**-' . substr($cleaned, -4);
            },
            
            'name' => function (string $value): string {
                $parts = explode(' ', $value);
                $masked = [];
                
                foreach ($parts as $part) {
                    if (strlen($part) <= 2) {
                        $masked[] = str_repeat('*', strlen($part));
                    } else {
                        $masked[] = substr($part, 0, 1) . str_repeat('*', strlen($part) - 2) . substr($part, -1);
                    }
                }
                
                return implode(' ', $masked);
            },
            
            'address' => function (string $value): string {
                // Keep first word (usually house number) and mask the rest
                $parts = explode(' ', $value, 2);
                if (count($parts) === 1) {
                    return $this->maskString($value, 2, 2);
                }
                
                return $parts[0] . ' ' . str_repeat('*', strlen($parts[1]));
            },
            
            'ip_address' => function (string $value): string {
                if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $parts = explode('.', $value);
                    return $parts[0] . '.' . $parts[1] . '.***.**';
                } elseif (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    $parts = explode(':', $value);
                    return implode(':', array_slice($parts, 0, 2)) . ':****:****:****:****';
                }
                
                return $this->maskString($value);
            }
        ];
    }

    /**
     * Mask data based on type
     */
    public function maskData(string $value, string $type): string
    {
        if (empty($value)) {
            return $value;
        }

        if (isset($this->maskingRules[$type])) {
            return $this->maskingRules[$type]($value);
        }

        // Default masking
        return $this->maskString($value);
    }

    /**
     * Mask multiple fields in an array
     */
    public function maskFields(array $data, array $fieldMappings): array
    {
        $masked = $data;
        
        foreach ($fieldMappings as $field => $type) {
            if (isset($masked[$field])) {
                $masked[$field] = $this->maskData($masked[$field], $type);
            }
        }
        
        return $masked;
    }

    /**
     * Generic string masking
     */
    public function maskString(string $value, int $startVisible = 2, int $endVisible = 2): string
    {
        $length = strlen($value);
        
        if ($length <= ($startVisible + $endVisible)) {
            return str_repeat('*', $length);
        }
        
        $start = substr($value, 0, $startVisible);
        $end = substr($value, -$endVisible);
        $middle = str_repeat('*', $length - $startVisible - $endVisible);
        
        return $start . $middle . $end;
    }

    /**
     * Add custom masking rule
     */
    public function addMaskingRule(string $type, callable $rule): void
    {
        $this->maskingRules[$type] = $rule;
    }

    /**
     * Anonymize data by replacing with fake data
     */
    public function anonymizeData(string $value, string $type): string
    {
        switch ($type) {
            case 'email':
                return 'user' . rand(1000, 9999) . '@example.com';
                
            case 'phone':
                return '+1-555-' . str_pad((string)rand(0, 9999), 4, '0', STR_PAD_LEFT);
                
            case 'name':
                $firstNames = ['John', 'Jane', 'Bob', 'Alice', 'Charlie', 'Diana'];
                $lastNames = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia'];
                return $firstNames[array_rand($firstNames)] . ' ' . $lastNames[array_rand($lastNames)];
                
            case 'address':
                $streets = ['Main St', 'Oak Ave', 'Pine Rd', 'Elm Dr', 'Cedar Ln'];
                return rand(100, 9999) . ' ' . $streets[array_rand($streets)];
                
            case 'credit_card':
                return '4***-****-****-' . str_pad((string)rand(0, 9999), 4, '0', STR_PAD_LEFT);
                
            case 'ssn':
                return '***-**-' . str_pad((string)rand(0, 9999), 4, '0', STR_PAD_LEFT);
                
            case 'ip_address':
                return '192.168.' . rand(1, 254) . '.' . rand(1, 254);
                
            default:
                return 'ANONYMIZED_' . strtoupper($type);
        }
    }

    /**
     * Check if data contains sensitive information
     */
    public function containsSensitiveData(string $value): array
    {
        $patterns = [
            'email' => '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
            'phone' => '/\b\d{3}[-.]?\d{3}[-.]?\d{4}\b/',
            'credit_card' => '/\b\d{4}[-\s]?\d{4}[-\s]?\d{4}[-\s]?\d{4}\b/',
            'ssn' => '/\b\d{3}-?\d{2}-?\d{4}\b/',
            'ip_address' => '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/'
        ];

        $found = [];
        
        foreach ($patterns as $type => $pattern) {
            if (preg_match($pattern, $value)) {
                $found[] = $type;
            }
        }
        
        return $found;
    }

    /**
     * Auto-mask detected sensitive data
     */
    public function autoMask(string $value): string
    {
        $sensitiveTypes = $this->containsSensitiveData($value);
        
        if (empty($sensitiveTypes)) {
            return $value;
        }
        
        $masked = $value;
        
        foreach ($sensitiveTypes as $type) {
            if (isset($this->maskingRules[$type])) {
                $masked = preg_replace_callback(
                    $this->getPatternForType($type),
                    function ($matches) use ($type) {
                        return $this->maskData($matches[0], $type);
                    },
                    $masked
                );
            }
        }
        
        return $masked;
    }

    /**
     * Get regex pattern for data type
     */
    private function getPatternForType(string $type): string
    {
        $patterns = [
            'email' => '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/',
            'phone' => '/\b\d{3}[-.]?\d{3}[-.]?\d{4}\b/',
            'credit_card' => '/\b\d{4}[-\s]?\d{4}[-\s]?\d{4}[-\s]?\d{4}\b/',
            'ssn' => '/\b\d{3}-?\d{2}-?\d{4}\b/',
            'ip_address' => '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/'
        ];

        return $patterns[$type] ?? '/./';
    }
}