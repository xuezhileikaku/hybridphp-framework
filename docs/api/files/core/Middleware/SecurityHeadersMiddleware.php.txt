<?php
namespace HybridPHP\Core\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Async Security Headers Middleware
 * Adds security-related HTTP headers to responses
 */
class SecurityHeadersMiddleware extends AbstractMiddleware
{
    private array $config;
    private array $defaultHeaders;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'hsts_max_age' => 31536000, // 1 year
            'hsts_include_subdomains' => true,
            'hsts_preload' => false,
            'frame_options' => 'DENY', // DENY, SAMEORIGIN, or ALLOW-FROM
            'content_type_options' => true,
            'xss_protection' => true,
            'referrer_policy' => 'strict-origin-when-cross-origin',
            'permissions_policy' => [
                'camera' => [],
                'microphone' => [],
                'geolocation' => [],
                'payment' => [],
            ],
            'expect_ct' => [
                'max_age' => 86400,
                'enforce' => false,
                'report_uri' => null,
            ],
            'custom_headers' => [],
        ], $config);

        $this->initializeDefaultHeaders();
    }

    /**
     * Initialize default security headers
     */
    private function initializeDefaultHeaders(): void
    {
        $this->defaultHeaders = [];

        // HTTP Strict Transport Security (HSTS)
        if (isset($this->config['hsts_max_age'])) {
            $hsts = "max-age={$this->config['hsts_max_age']}";
            
            if ($this->config['hsts_include_subdomains']) {
                $hsts .= '; includeSubDomains';
            }
            
            if ($this->config['hsts_preload']) {
                $hsts .= '; preload';
            }
            
            $this->defaultHeaders['Strict-Transport-Security'] = $hsts;
        }

        // X-Frame-Options
        if ($this->config['frame_options']) {
            $this->defaultHeaders['X-Frame-Options'] = $this->config['frame_options'];
        }

        // X-Content-Type-Options
        if ($this->config['content_type_options']) {
            $this->defaultHeaders['X-Content-Type-Options'] = 'nosniff';
        }

        // X-XSS-Protection
        if ($this->config['xss_protection']) {
            $this->defaultHeaders['X-XSS-Protection'] = '1; mode=block';
        }

        // Referrer-Policy
        if ($this->config['referrer_policy']) {
            $this->defaultHeaders['Referrer-Policy'] = $this->config['referrer_policy'];
        }

        // Permissions-Policy (formerly Feature-Policy)
        if (!empty($this->config['permissions_policy'])) {
            $policies = [];
            foreach ($this->config['permissions_policy'] as $directive => $allowlist) {
                if (empty($allowlist)) {
                    $policies[] = "{$directive}=()";
                } else {
                    $origins = implode(' ', array_map(fn($origin) => "\"{$origin}\"", $allowlist));
                    $policies[] = "{$directive}=({$origins})";
                }
            }
            
            if (!empty($policies)) {
                $this->defaultHeaders['Permissions-Policy'] = implode(', ', $policies);
            }
        }

        // Expect-CT
        if (!empty($this->config['expect_ct'])) {
            $expectCt = "max-age={$this->config['expect_ct']['max_age']}";
            
            if ($this->config['expect_ct']['enforce']) {
                $expectCt .= ', enforce';
            }
            
            if ($this->config['expect_ct']['report_uri']) {
                $expectCt .= ", report-uri=\"{$this->config['expect_ct']['report_uri']}\"";
            }
            
            $this->defaultHeaders['Expect-CT'] = $expectCt;
        }

        // Custom headers
        if (!empty($this->config['custom_headers'])) {
            $this->defaultHeaders = array_merge($this->defaultHeaders, $this->config['custom_headers']);
        }
    }

    protected function after(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Add all security headers
        foreach ($this->defaultHeaders as $name => $value) {
            // Don't override existing headers unless explicitly configured
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        // Add server information removal
        $response = $response->withoutHeader('Server');
        $response = $response->withoutHeader('X-Powered-By');

        // Add security-focused cache control for sensitive pages
        if ($this->isSensitivePage($request)) {
            $response = $response->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate, private');
            $response = $response->withHeader('Pragma', 'no-cache');
            $response = $response->withHeader('Expires', '0');
        }

        return $response;
    }

    /**
     * Check if the current page is sensitive and should not be cached
     *
     * @param ServerRequestInterface $request
     * @return bool
     */
    private function isSensitivePage(ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();
        
        $sensitivePatterns = [
            '/login',
            '/logout',
            '/admin',
            '/dashboard',
            '/profile',
            '/account',
            '/payment',
            '/checkout',
        ];

        foreach ($sensitivePatterns as $pattern) {
            if (str_starts_with($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Set HSTS configuration
     *
     * @param int $maxAge
     * @param bool $includeSubdomains
     * @param bool $preload
     * @return self
     */
    public function setHsts(int $maxAge, bool $includeSubdomains = true, bool $preload = false): self
    {
        $this->config['hsts_max_age'] = $maxAge;
        $this->config['hsts_include_subdomains'] = $includeSubdomains;
        $this->config['hsts_preload'] = $preload;
        
        $this->initializeDefaultHeaders();
        
        return $this;
    }

    /**
     * Set frame options
     *
     * @param string $option DENY, SAMEORIGIN, or ALLOW-FROM
     * @return self
     */
    public function setFrameOptions(string $option): self
    {
        $this->config['frame_options'] = $option;
        $this->initializeDefaultHeaders();
        
        return $this;
    }

    /**
     * Set referrer policy
     *
     * @param string $policy
     * @return self
     */
    public function setReferrerPolicy(string $policy): self
    {
        $this->config['referrer_policy'] = $policy;
        $this->initializeDefaultHeaders();
        
        return $this;
    }

    /**
     * Add custom security header
     *
     * @param string $name
     * @param string $value
     * @return self
     */
    public function addCustomHeader(string $name, string $value): self
    {
        $this->config['custom_headers'][$name] = $value;
        $this->initializeDefaultHeaders();
        
        return $this;
    }

    /**
     * Set permissions policy
     *
     * @param array $policies
     * @return self
     */
    public function setPermissionsPolicy(array $policies): self
    {
        $this->config['permissions_policy'] = $policies;
        $this->initializeDefaultHeaders();
        
        return $this;
    }

    /**
     * Get current security headers configuration
     *
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->defaultHeaders;
    }
}