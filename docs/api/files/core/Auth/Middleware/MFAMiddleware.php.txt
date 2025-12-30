<?php

declare(strict_types=1);

namespace HybridPHP\Core\Auth\Middleware;

use Amp\Future;
use HybridPHP\Core\MiddlewareInterface;
use HybridPHP\Core\Auth\MFA\MFAManager;
use HybridPHP\Core\Http\Request;
use HybridPHP\Core\Http\Response;
use function Amp\async;

/**
 * Multi-Factor Authentication middleware
 */
class MFAMiddleware implements MiddlewareInterface
{
    private MFAManager $mfaManager;
    private array $config;

    public function __construct(MFAManager $mfaManager, array $config = [])
    {
        $this->mfaManager = $mfaManager;
        $this->config = array_merge([
            'mfa_verification_url' => '/auth/mfa/verify',
            'skip_routes' => ['/auth/mfa/verify', '/auth/mfa/send', '/auth/logout'],
        ], $config);
    }

    /**
     * Process the middleware
     *
     * @param Request $request
     * @param callable $next
     * @return Future<Response>
     */
    public function process(Request $request, callable $next): Future
    {
        return async(function () use ($request, $next) {
            $user = $request->getAttribute('user');
            
            if (!$user) {
                return $next($request)->await();
            }

            // Skip MFA check for certain routes
            if ($this->shouldSkipMFA($request)) {
                return $next($request)->await();
            }

            // Check if MFA is enabled for user
            $mfaEnabled = $this->mfaManager->isEnabledForUser($user)->await();
            
            if (!$mfaEnabled) {
                return $next($request)->await();
            }

            // Check if MFA is already verified in this session
            if ($this->isMFAVerified($request)) {
                return $next($request)->await();
            }

            // MFA verification required
            return $this->requireMFAVerification($request);
        });
    }

    /**
     * Check if MFA should be skipped for this request
     *
     * @param Request $request
     * @return bool
     */
    private function shouldSkipMFA(Request $request): bool
    {
        $path = $request->getPath();
        
        foreach ($this->config['skip_routes'] as $skipRoute) {
            if (str_starts_with($path, $skipRoute)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if MFA is already verified in this session
     *
     * @param Request $request
     * @return bool
     */
    private function isMFAVerified(Request $request): bool
    {
        // Check session or token for MFA verification status
        $mfaVerified = $request->getSession('mfa_verified', false);
        
        // Also check for MFA token in headers for API requests
        if (!$mfaVerified && $this->isApiRequest($request)) {
            $mfaToken = $request->getHeader('X-MFA-Token');
            if ($mfaToken) {
                // Verify MFA token (simplified - in real implementation, validate the token)
                $mfaVerified = $this->validateMFAToken($mfaToken);
            }
        }

        return $mfaVerified;
    }

    /**
     * Require MFA verification
     *
     * @param Request $request
     * @return Response
     */
    private function requireMFAVerification(Request $request): Response
    {
        if ($this->isApiRequest($request)) {
            return new Response(403, [], json_encode([
                'error' => 'MFA Required',
                'message' => 'Multi-factor authentication verification required',
                'mfa_required' => true
            ]));
        }

        return new Response(302, [
            'Location' => $this->config['mfa_verification_url']
        ]);
    }

    /**
     * Validate MFA token (simplified implementation)
     *
     * @param string $token
     * @return bool
     */
    private function validateMFAToken(string $token): bool
    {
        // In a real implementation, this would validate the MFA token
        // against stored session data or JWT claims
        return !empty($token) && strlen($token) > 10;
    }

    /**
     * Check if request is API request
     *
     * @param Request $request
     * @return bool
     */
    private function isApiRequest(Request $request): bool
    {
        $acceptHeader = $request->getHeader('Accept');
        $contentType = $request->getHeader('Content-Type');
        
        return str_contains($acceptHeader, 'application/json') ||
               str_contains($contentType, 'application/json') ||
               str_starts_with($request->getPath(), '/api/');
    }
}