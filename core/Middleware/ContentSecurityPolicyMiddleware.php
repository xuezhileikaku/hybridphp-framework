<?php
namespace HybridPHP\Core\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Async Content Security Policy (CSP) Middleware
 * Implements comprehensive CSP protection against XSS and data injection attacks
 */
class ContentSecurityPolicyMiddleware extends AbstractMiddleware
{
    private array $config;
    private array $directives;
    private array $nonces;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'report_only' => false,
            'report_uri' => null,
            'report_to' => null,
            'upgrade_insecure_requests' => true,
            'block_all_mixed_content' => true,
            'nonce_length' => 32,
            'auto_nonce' => true,
        ], $config);

        $this->initializeDefaultDirectives();
        $this->nonces = [];
    }

    /**
     * Initialize default CSP directives
     */
    private function initializeDefaultDirectives(): void
    {
        $this->directives = array_merge([
            'default-src' => ["'self'"],
            'script-src' => ["'self'"],
            'style-src' => ["'self'", "'unsafe-inline'"], // unsafe-inline needed for some frameworks
            'img-src' => ["'self'", 'data:', 'https:'],
            'font-src' => ["'self'", 'https:', 'data:'],
            'connect-src' => ["'self'"],
            'media-src' => ["'self'"],
            'object-src' => ["'none'"],
            'child-src' => ["'self'"],
            'frame-src' => ["'self'"],
            'worker-src' => ["'self'"],
            'frame-ancestors' => ["'self'"],
            'form-action' => ["'self'"],
            'base-uri' => ["'self'"],
            'manifest-src' => ["'self'"],
        ], $this->config['directives'] ?? []);

        // Add upgrade-insecure-requests if enabled
        if ($this->config['upgrade_insecure_requests']) {
            $this->directives['upgrade-insecure-requests'] = [];
        }

        // Add block-all-mixed-content if enabled
        if ($this->config['block_all_mixed_content']) {
            $this->directives['block-all-mixed-content'] = [];
        }

        // Add report directives
        if ($this->config['report_uri']) {
            $this->directives['report-uri'] = [$this->config['report_uri']];
        }

        if ($this->config['report_to']) {
            $this->directives['report-to'] = [$this->config['report_to']];
        }
    }

    protected function before(ServerRequestInterface $request): ServerRequestInterface
    {
        // Generate nonces for this request if auto-nonce is enabled
        if ($this->config['auto_nonce']) {
            $this->nonces = [
                'script' => $this->generateNonce(),
                'style' => $this->generateNonce(),
            ];

            // Add nonces to request attributes for use in templates
            $request = $request->withAttribute('csp_script_nonce', $this->nonces['script']);
            $request = $request->withAttribute('csp_style_nonce', $this->nonces['style']);
        }

        return $request;
    }

    protected function after(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $cspHeader = $this->buildCspHeader();
        
        if ($cspHeader) {
            $headerName = $this->config['report_only'] ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
            $response = $response->withHeader($headerName, $cspHeader);
        }

        return $response;
    }

    /**
     * Build the CSP header value
     *
     * @return string
     */
    private function buildCspHeader(): string
    {
        $policies = [];
        
        foreach ($this->directives as $directive => $sources) {
            if (empty($sources)) {
                // Directives without sources (like upgrade-insecure-requests)
                $policies[] = $directive;
            } else {
                // Add nonces to script-src and style-src if available
                if ($directive === 'script-src' && isset($this->nonces['script'])) {
                    $sources[] = "'nonce-{$this->nonces['script']}'";
                }
                
                if ($directive === 'style-src' && isset($this->nonces['style'])) {
                    $sources[] = "'nonce-{$this->nonces['style']}'";
                }
                
                $policies[] = $directive . ' ' . implode(' ', $sources);
            }
        }

        return implode('; ', $policies);
    }

    /**
     * Generate a cryptographically secure nonce
     *
     * @return string
     */
    private function generateNonce(): string
    {
        return base64_encode(random_bytes($this->config['nonce_length']));
    }

    /**
     * Add source to a directive
     *
     * @param string $directive
     * @param string $source
     * @return self
     */
    public function addSource(string $directive, string $source): self
    {
        if (!isset($this->directives[$directive])) {
            $this->directives[$directive] = [];
        }

        if (!in_array($source, $this->directives[$directive])) {
            $this->directives[$directive][] = $source;
        }

        return $this;
    }

    /**
     * Remove source from a directive
     *
     * @param string $directive
     * @param string $source
     * @return self
     */
    public function removeSource(string $directive, string $source): self
    {
        if (isset($this->directives[$directive])) {
            $this->directives[$directive] = array_filter(
                $this->directives[$directive],
                fn($s) => $s !== $source
            );
        }

        return $this;
    }

    /**
     * Set directive sources (replaces existing)
     *
     * @param string $directive
     * @param array $sources
     * @return self
     */
    public function setDirective(string $directive, array $sources): self
    {
        $this->directives[$directive] = $sources;
        return $this;
    }

    /**
     * Remove a directive entirely
     *
     * @param string $directive
     * @return self
     */
    public function removeDirective(string $directive): self
    {
        unset($this->directives[$directive]);
        return $this;
    }

    /**
     * Allow inline scripts (adds 'unsafe-inline' to script-src)
     *
     * @return self
     */
    public function allowInlineScripts(): self
    {
        return $this->addSource('script-src', "'unsafe-inline'");
    }

    /**
     * Allow inline styles (adds 'unsafe-inline' to style-src)
     *
     * @return self
     */
    public function allowInlineStyles(): self
    {
        return $this->addSource('style-src', "'unsafe-inline'");
    }

    /**
     * Allow eval() in scripts (adds 'unsafe-eval' to script-src)
     *
     * @return self
     */
    public function allowEval(): self
    {
        return $this->addSource('script-src', "'unsafe-eval'");
    }

    /**
     * Add Google Fonts support
     *
     * @return self
     */
    public function allowGoogleFonts(): self
    {
        return $this
            ->addSource('font-src', 'https://fonts.gstatic.com')
            ->addSource('style-src', 'https://fonts.googleapis.com');
    }

    /**
     * Add Google Analytics support
     *
     * @return self
     */
    public function allowGoogleAnalytics(): self
    {
        return $this
            ->addSource('script-src', 'https://www.google-analytics.com')
            ->addSource('script-src', 'https://www.googletagmanager.com')
            ->addSource('img-src', 'https://www.google-analytics.com')
            ->addSource('connect-src', 'https://www.google-analytics.com');
    }

    /**
     * Add CDN support for common libraries
     *
     * @param array $cdns
     * @return self
     */
    public function allowCdns(array $cdns = []): self
    {
        $defaultCdns = [
            'https://cdnjs.cloudflare.com',
            'https://cdn.jsdelivr.net',
            'https://unpkg.com',
        ];

        $cdns = array_merge($defaultCdns, $cdns);

        foreach ($cdns as $cdn) {
            $this->addSource('script-src', $cdn);
            $this->addSource('style-src', $cdn);
            $this->addSource('font-src', $cdn);
        }

        return $this;
    }

    /**
     * Set report-only mode
     *
     * @param bool $reportOnly
     * @return self
     */
    public function setReportOnly(bool $reportOnly = true): self
    {
        $this->config['report_only'] = $reportOnly;
        return $this;
    }

    /**
     * Set report URI
     *
     * @param string $uri
     * @return self
     */
    public function setReportUri(string $uri): self
    {
        $this->config['report_uri'] = $uri;
        $this->directives['report-uri'] = [$uri];
        return $this;
    }

    /**
     * Get current nonces
     *
     * @return array
     */
    public function getNonces(): array
    {
        return $this->nonces;
    }

    /**
     * Get script nonce
     *
     * @return string|null
     */
    public function getScriptNonce(): ?string
    {
        return $this->nonces['script'] ?? null;
    }

    /**
     * Get style nonce
     *
     * @return string|null
     */
    public function getStyleNonce(): ?string
    {
        return $this->nonces['style'] ?? null;
    }

    /**
     * Get all directives
     *
     * @return array
     */
    public function getDirectives(): array
    {
        return $this->directives;
    }
}