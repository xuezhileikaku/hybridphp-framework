<?php
namespace App\Middleware;

use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

class CorsMiddleware
{
    public function __invoke(Request $request, callable $next)
    {
        $response = $next($request);
        
        if (!$response instanceof Response) {
            $response = new Response(200, [], (string)$response);
        }
        
        $response->header('Access-Control-Allow-Origin', '*');
        $response->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
        
        if ($request->method() === 'OPTIONS') {
            return new Response(200, [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, Authorization',
            ], '');
        }
        
        return $response;
    }
}
