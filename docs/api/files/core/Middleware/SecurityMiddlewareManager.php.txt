<?php
namespace HybridPHP\Core\Middleware;

use HybridPHP\Core\MiddlewareManager;

/**
 * Security Middleware Manager
 * Manages and configures all security-related middleware
 */
class SecurityMiddlewareManager
{
    private MiddlewareManager $middlewareManager;
    private array $config;
    private array $securityMiddleware = [];

    public function __construct(MiddlewareManager $middlewareManager, array $config = [])
    {
        $this->middlewareManager = $middlewareManager;
        $this->config = array_merge([
            'csrf_protection' => [
                'enabled' => true,
                'config' => [],
            ],
            'xss_protection' => [
                'enabled' => true,
                'config' => [],
            ],
            'sql_injection_protection' => [
                'enabled' => true,
                'config' => [],
            ],
            'input_validation' => [
                'enabled' => true,
                'config' => [],
            ],
            'security_headers' => [
                'enabled' => true,
                'config' => [],
            ],
            'content_security_policy' => [
                'enabled' => true,
                'config' => [],
            ],
        ], $config);

        $this->initializeSecurityMiddleware();
    }

    /**
     * Initialize all security middleware
     */
    private function initializeSecurityMiddleware(): void
    {
        // CSRF Protection
        if ($this->config['csrf_protection']['enabled']) {
            $this->securityMiddleware['csrf'] = new CsrfProtectionMiddleware(
                $this->config['csrf_protection']['config']
            );
        }

        // XSS Protection
        if ($this->config['xss_protection']['enabled']) {
            $this->securityMiddleware['xss'] = new XssProtectionMiddleware(
                $this->config['xss_protection']['config']
            );
        }

        // SQL Injection Protection
        if ($this->config['sql_injection_protection']['enabled']) {
            $this->securityMiddleware['sql_injection'] = new SqlInjectionProtectionMiddleware(
                $this->config['sql_injection_protection']['config']
            );
        }

        // Input Validation
        if ($this->config['input_validation']['enabled']) {
            $this->securityMiddleware['input_validation'] = new InputValidationMiddleware(
                $this->config['input_validation']['config']
            );
        }

        // Security Headers
        if ($this->config['security_headers']['enabled']) {
            $this->securityMiddleware['security_headers'] = new SecurityHeadersMiddleware(
                $this->config['security_headers']['config']
            );
        }

        // Content Security Policy
        if ($this->config['content_security_policy']['enabled']) {
            $this->securityMiddleware['csp'] = new ContentSecurityPolicyMiddleware(
                $this->config['content_security_policy']['config']
            );
        }
    }

    /**
     * Register all security middleware as global middleware
     *
     * @param int $priority
     * @return self
     */
    public function registerGlobalSecurity(int $priority = 100): self
    {
        // Register in order of execution priority
        $order = [
            'input_validation',    // First: validate and sanitize input
            'sql_injection',      // Second: check for SQL injection
            'xss',               // Third: XSS protection
            'csrf',              // Fourth: CSRF protection
            'security_headers',   // Fifth: add security headers
            'csp',               // Last: add CSP headers
        ];

        foreach ($order as $index => $middleware) {
            if (isset($this->securityMiddleware[$middleware])) {
                $this->middlewareManager->addGlobal(
                    $this->securityMiddleware[$middleware],
                    $priority - $index
                );
            }
        }

        return $this;
    }

    /**
     * Register security middleware for a specific group
     *
     * @param string $group
     * @param array $middleware
     * @param int $priority
     * @return self
     */
    public function registerGroupSecurity(string $group, array $middleware = [], int $priority = 100): self
    {
        $middlewareToRegister = empty($middleware) ? array_keys($this->securityMiddleware) : $middleware;

        foreach ($middlewareToRegister as $index => $middlewareName) {
            if (isset($this->securityMiddleware[$middlewareName])) {
                $this->middlewareManager->addToGroup(
                    $group,
                    $this->securityMiddleware[$middlewareName],
                    $priority - $index
                );
            }
        }

        return $this;
    }

    /**
     * Get a specific security middleware instance
     *
     * @param string $name
     * @return mixed|null
     */
    public function getSecurityMiddleware(string $name)
    {
        return $this->securityMiddleware[$name] ?? null;
    }

    /**
     * Configure CSRF protection
     *
     * @param array $config
     * @return self
     */
    public function configureCsrf(array $config): self
    {
        $this->config['csrf_protection']['config'] = array_merge(
            $this->config['csrf_protection']['config'],
            $config
        );

        if (isset($this->securityMiddleware['csrf'])) {
            $this->securityMiddleware['csrf'] = new CsrfProtectionMiddleware(
                $this->config['csrf_protection']['config']
            );
        }

        return $this;
    }

    /**
     * Configure XSS protection
     *
     * @param array $config
     * @return self
     */
    public function configureXss(array $config): self
    {
        $this->config['xss_protection']['config'] = array_merge(
            $this->config['xss_protection']['config'],
            $config
        );

        if (isset($this->securityMiddleware['xss'])) {
            $this->securityMiddleware['xss'] = new XssProtectionMiddleware(
                $this->config['xss_protection']['config']
            );
        }

        return $this;
    }

    /**
     * Configure SQL injection protection
     *
     * @param array $config
     * @return self
     */
    public function configureSqlInjection(array $config): self
    {
        $this->config['sql_injection_protection']['config'] = array_merge(
            $this->config['sql_injection_protection']['config'],
            $config
        );

        if (isset($this->securityMiddleware['sql_injection'])) {
            $this->securityMiddleware['sql_injection'] = new SqlInjectionProtectionMiddleware(
                $this->config['sql_injection_protection']['config']
            );
        }

        return $this;
    }

    /**
     * Configure input validation
     *
     * @param array $config
     * @return self
     */
    public function configureInputValidation(array $config): self
    {
        $this->config['input_validation']['config'] = array_merge(
            $this->config['input_validation']['config'],
            $config
        );

        if (isset($this->securityMiddleware['input_validation'])) {
            $this->securityMiddleware['input_validation'] = new InputValidationMiddleware(
                $this->config['input_validation']['config']
            );
        }

        return $this;
    }

    /**
     * Configure security headers
     *
     * @param array $config
     * @return self
     */
    public function configureSecurityHeaders(array $config): self
    {
        $this->config['security_headers']['config'] = array_merge(
            $this->config['security_headers']['config'],
            $config
        );

        if (isset($this->securityMiddleware['security_headers'])) {
            $this->securityMiddleware['security_headers'] = new SecurityHeadersMiddleware(
                $this->config['security_headers']['config']
            );
        }

        return $this;
    }

    /**
     * Configure Content Security Policy
     *
     * @param array $config
     * @return self
     */
    public function configureContentSecurityPolicy(array $config): self
    {
        $this->config['content_security_policy']['config'] = array_merge(
            $this->config['content_security_policy']['config'],
            $config
        );

        if (isset($this->securityMiddleware['csp'])) {
            $this->securityMiddleware['csp'] = new ContentSecurityPolicyMiddleware(
                $this->config['content_security_policy']['config']
            );
        }

        return $this;
    }

    /**
     * Enable/disable specific security middleware
     *
     * @param string $middleware
     * @param bool $enabled
     * @return self
     */
    public function setEnabled(string $middleware, bool $enabled): self
    {
        if (isset($this->config[$middleware])) {
            $this->config[$middleware]['enabled'] = $enabled;
            
            if ($enabled && !isset($this->securityMiddleware[$middleware])) {
                $this->initializeSecurityMiddleware();
            } elseif (!$enabled && isset($this->securityMiddleware[$middleware])) {
                unset($this->securityMiddleware[$middleware]);
            }
        }

        return $this;
    }

    /**
     * Get security configuration
     *
     * @return array
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Get all security middleware instances
     *
     * @return array
     */
    public function getAllSecurityMiddleware(): array
    {
        return $this->securityMiddleware;
    }

    /**
     * Create a preset configuration for different security levels
     *
     * @param string $level 'basic', 'standard', 'strict'
     * @return array
     */
    public static function createPresetConfig(string $level = 'standard'): array
    {
        switch ($level) {
            case 'basic':
                return [
                    'csrf_protection' => ['enabled' => true],
                    'xss_protection' => ['enabled' => true, 'config' => ['strict_mode' => false]],
                    'sql_injection_protection' => ['enabled' => true, 'config' => ['strict_mode' => false]],
                    'input_validation' => ['enabled' => true, 'config' => ['strict_validation' => false]],
                    'security_headers' => ['enabled' => true],
                    'content_security_policy' => ['enabled' => false],
                ];

            case 'strict':
                return [
                    'csrf_protection' => ['enabled' => true, 'config' => ['regenerate_on_use' => true]],
                    'xss_protection' => ['enabled' => true, 'config' => ['strict_mode' => true]],
                    'sql_injection_protection' => ['enabled' => true, 'config' => ['strict_mode' => true]],
                    'input_validation' => ['enabled' => true, 'config' => ['strict_validation' => true]],
                    'security_headers' => ['enabled' => true, 'config' => ['hsts_preload' => true]],
                    'content_security_policy' => ['enabled' => true, 'config' => ['report_only' => false]],
                ];

            case 'standard':
            default:
                return [
                    'csrf_protection' => ['enabled' => true],
                    'xss_protection' => ['enabled' => true],
                    'sql_injection_protection' => ['enabled' => true],
                    'input_validation' => ['enabled' => true],
                    'security_headers' => ['enabled' => true],
                    'content_security_policy' => ['enabled' => true, 'config' => ['report_only' => true]],
                ];
        }
    }
}