<?php

declare(strict_types=1);

use HybridPHP\Core\Routing\Router;
use App\Controllers\AuthController;
use App\Controllers\UserController;
use HybridPHP\Core\Auth\Middleware\AuthMiddleware;
use HybridPHP\Core\Auth\Middleware\PermissionMiddleware;
use HybridPHP\Core\Auth\Middleware\RoleMiddleware;
use HybridPHP\Core\Auth\Middleware\MFAMiddleware;

/**
 * Authentication routes
 */

// Public authentication routes
Router::post('/auth/login', [AuthController::class, 'login']);
Router::post('/auth/logout', [AuthController::class, 'logout']);

// MFA routes
Router::post('/auth/mfa/send', [AuthController::class, 'sendMFACode']);
Router::post('/auth/mfa/verify', [AuthController::class, 'verifyMFACode']);

// Protected routes (require authentication)
Router::group(['middleware' => [AuthMiddleware::class]], function () {
    // User profile routes
    Router::get('/auth/me', [AuthController::class, 'me']);
    
    // MFA management routes
    Router::post('/auth/mfa/enable', [AuthController::class, 'enableMFA']);
    Router::post('/auth/mfa/disable', [AuthController::class, 'disableMFA']);
    Router::post('/auth/mfa/backup-codes', [AuthController::class, 'generateBackupCodes']);
});

// Admin routes (require authentication + admin role)
Router::group([
    'prefix' => '/admin',
    'middleware' => [
        AuthMiddleware::class,
        new RoleMiddleware(rbac(), ['admin', 'super_admin'])
    ]
], function () {
    // User management routes
    Router::get('/users', [UserController::class, 'index']);
    Router::get('/users/{id}', [UserController::class, 'show']);
    Router::post('/users', [UserController::class, 'create']);
    Router::put('/users/{id}', [UserController::class, 'update']);
    Router::delete('/users/{id}', [UserController::class, 'delete']);
    
    // Role and permission management
    Router::post('/users/{id}/roles', [UserController::class, 'assignRole']);
    Router::delete('/users/{id}/roles', [UserController::class, 'removeRole']);
    Router::post('/users/{id}/permissions', [UserController::class, 'grantPermission']);
    Router::delete('/users/{id}/permissions', [UserController::class, 'revokePermission']);
});

// API routes with JWT authentication
Router::group([
    'prefix' => '/api/v1',
    'middleware' => [
        new AuthMiddleware(auth(), ['guard' => 'jwt'])
    ]
], function () {
    Router::get('/user', [AuthController::class, 'me']);
    
    // Protected API endpoints with specific permissions
    Router::group([
        'middleware' => [new PermissionMiddleware(rbac(), 'users.read')]
    ], function () {
        Router::get('/users', [UserController::class, 'index']);
        Router::get('/users/{id}', [UserController::class, 'show']);
    });
    
    Router::group([
        'middleware' => [new PermissionMiddleware(rbac(), 'users.write')]
    ], function () {
        Router::post('/users', [UserController::class, 'create']);
        Router::put('/users/{id}', [UserController::class, 'update']);
    });
    
    Router::group([
        'middleware' => [new PermissionMiddleware(rbac(), 'users.delete')]
    ], function () {
        Router::delete('/users/{id}', [UserController::class, 'delete']);
    });
});

// Routes with MFA requirement
Router::group([
    'prefix' => '/secure',
    'middleware' => [
        AuthMiddleware::class,
        MFAMiddleware::class
    ]
], function () {
    Router::get('/dashboard', function () {
        return response()->json(['message' => 'Secure dashboard - MFA verified']);
    });
    
    Router::get('/settings', function () {
        return response()->json(['message' => 'Secure settings - MFA verified']);
    });
});