<?php
namespace App\Controllers;

use HybridPHP\Core\Http\Request;
use HybridPHP\Core\Http\Response;

class HomeController
{
    public function index(Request $request, array $params = []): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'message' => 'Welcome to HybridPHP Framework',
            'version' => '1.0.0-alpha',
            'timestamp' => date('c'),
            'framework' => 'HybridPHP (Yii2 + Workerman + AMPHP)'
        ]));
    }

    public function hello(Request $request, array $params = []): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'message' => 'Hello from HybridPHP!',
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'timestamp' => date('c')
        ]));
    }

    public function greet(Request $request, array $params = []): Response
    {
        $name = $params['name'] ?? 'World';
        
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'message' => "Hello, {$name}!",
            'name' => $name,
            'timestamp' => date('c')
        ]));
    }
}