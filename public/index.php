<?php

/**
 * HybridPHP Framework - Web Entry Point
 * 
 * This file serves as the web entry point for the HybridPHP Framework.
 * It bootstraps the application and handles HTTP requests.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use HybridPHP\Core\Application;

// Load environment variables
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

// Create and run the application
$app = new Application(__DIR__ . '/..');
$app->run();