<?php

use HybridPHP\Core\Routing\RouterFacade as Router;

// Basic routes
Router::get('/', [App\Controllers\HomeController::class, 'index'])->name('home');
Router::get('/hello', [App\Controllers\HomeController::class, 'hello'])->name('hello');
Router::get('/hello/{name}', [App\Controllers\HomeController::class, 'greet'])
    ->name('greet')
    ->where('name', '[a-zA-Z]+');

// Status endpoint
Router::get('/status', [App\Controllers\StatusController::class, 'index'])->name('status');

// Health check endpoints
Router::get('/health', [App\Controllers\HealthController::class, 'check'])->name('health');
Router::get('/health/liveness', [App\Controllers\HealthController::class, 'liveness'])->name('health.liveness');
Router::get('/health/readiness', [App\Controllers\HealthController::class, 'readiness'])->name('health.readiness');

// API routes
Router::group(['prefix' => 'api/v1'], function () {
    Router::get('/health', [App\Controllers\HealthController::class, 'apiCheck'])->name('api.health');
    Router::get('/status', [App\Controllers\StatusController::class, 'apiStatus'])->name('api.status');
});
