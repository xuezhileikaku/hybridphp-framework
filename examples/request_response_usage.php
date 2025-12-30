<?php

require_once __DIR__ . '/../vendor/autoload.php';

use HybridPHP\Core\Http\Request;
use HybridPHP\Core\Http\Response;
use HybridPHP\Core\Http\RequestFactory;
use HybridPHP\Core\Http\ResponseFactory;
use HybridPHP\Core\Http\RequestValidator;
use HybridPHP\Core\Http\ValidationChain;

echo "=== HybridPHP Request/Response System Demo ===\n\n";

// 1. Creating requests
echo "1. Creating Requests:\n";

// From globals (in real application)
// $request = RequestFactory::fromGlobals();

// For testing/demo
$request = RequestFactory::createJson('POST', '/api/users', [
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'age' => 30
]);

echo "Request method: " . $request->getMethod() . "\n";
echo "Request URI: " . $request->getUri() . "\n";
echo "Request data: " . json_encode($request->getParsedBody()) . "\n\n";

// 2. Request validation
echo "2. Request Validation:\n";

// Simple validation
$isValid = $request->validate([
    'name' => 'required|string|min:2',
    'email' => 'required|email',
    'age' => 'required|integer|min:18'
]);

echo "Validation result: " . ($isValid ? 'PASSED' : 'FAILED') . "\n";
if (!$isValid) {
    echo "Errors: " . json_encode($request->getErrors()) . "\n";
}

// Fluent validation
$validator = ValidationChain::make($request)
    ->field('name')->required()->string()->min(2)
    ->field('email')->required()->email()
    ->field('age')->required()->integer()->min(18);

$isValidFluent = $validator->validate();
echo "Fluent validation: " . ($isValidFluent ? 'PASSED' : 'FAILED') . "\n\n";

// 3. Custom validation rules
echo "3. Custom Validation Rules:\n";

$customValidator = ValidationChain::make($request)
    ->rule('adult', function($value) {
        return $value >= 18 ? true : 'Must be an adult (18+)';
    })
    ->field('age')->required()->custom(function($value) {
        return $value >= 21 ? true : 'Must be 21 or older for this service';
    });

$customValid = $customValidator->validate();
echo "Custom validation: " . ($customValid ? 'PASSED' : 'FAILED') . "\n";
if (!$customValid) {
    echo "Custom errors: " . json_encode($customValidator->getErrors()) . "\n";
}
echo "\n";

// 4. Response creation
echo "4. Response Creation:\n";

// JSON response
$jsonResponse = ResponseFactory::json(['message' => 'User created', 'id' => 123]);
echo "JSON Response: " . $jsonResponse->getBody() . "\n";

// XML response
$xmlResponse = ResponseFactory::xml(['user' => ['name' => 'John', 'id' => 123]]);
echo "XML Response: " . $xmlResponse->getBody() . "\n";

// API responses
$successResponse = ResponseFactory::success(['user_id' => 123], 'User created successfully');
echo "Success Response: " . $successResponse->getBody() . "\n";

$errorResponse = ResponseFactory::validationError(['email' => ['Email is required']]);
echo "Error Response: " . $errorResponse->getBody() . "\n\n";

// 5. Content negotiation
echo "5. Content Negotiation:\n";

// Create request that accepts JSON
$jsonRequest = RequestFactory::create('GET', '/api/users', ['Accept' => 'application/json']);
$negotiatedResponse = ResponseFactory::negotiate(['users' => []], $jsonRequest);
echo "Negotiated (JSON): " . $negotiatedResponse->getHeaderLine('Content-Type') . "\n";

// Create request that accepts XML
$xmlRequest = RequestFactory::create('GET', '/api/users', ['Accept' => 'application/xml']);
$negotiatedXmlResponse = ResponseFactory::negotiate(['users' => []], $xmlRequest);
echo "Negotiated (XML): " . $negotiatedXmlResponse->getHeaderLine('Content-Type') . "\n\n";

// 6. File upload handling
echo "6. File Upload Handling:\n";

// Create a multipart request with file
$fileRequest = RequestFactory::createMultipart('POST', '/upload', 
    ['description' => 'Test file'],
    ['avatar' => [
        'content' => 'fake image content',
        'name' => 'avatar.jpg',
        'type' => 'image/jpeg'
    ]]
);

$uploadedFiles = $fileRequest->getUploadedFiles();
if (isset($uploadedFiles['avatar'])) {
    $file = $uploadedFiles['avatar'];
    echo "Uploaded file: " . $file->getClientFilename() . "\n";
    echo "File size: " . $file->getSize() . " bytes\n";
    echo "File type: " . $file->getClientMediaType() . "\n";
    echo "Is valid: " . ($file->isValid() ? 'YES' : 'NO') . "\n";
    
    // Validate file
    $fileErrors = $file->validate([
        'required' => true,
        'maxSize' => 1024 * 1024, // 1MB
        'extensions' => ['jpg', 'png', 'gif'],
        'imageOnly' => true
    ]);
    
    echo "File validation errors: " . (empty($fileErrors) ? 'None' : implode(', ', $fileErrors)) . "\n";
}
echo "\n";

// 7. Response caching
echo "7. Response Caching:\n";

$cachedResponse = ResponseFactory::cached(['data' => 'cached content'], 3600);
echo "Cache headers: " . $cachedResponse->getHeaderLine('Cache-Control') . "\n";

$noCacheResponse = ResponseFactory::json(['data' => 'no cache'])->withNoCache();
echo "No-cache headers: " . $noCacheResponse->getHeaderLine('Cache-Control') . "\n\n";

// 8. CORS support
echo "8. CORS Support:\n";

$corsResponse = ResponseFactory::cors(['data' => 'cors enabled']);
echo "CORS Origin: " . $corsResponse->getHeaderLine('Access-Control-Allow-Origin') . "\n";
echo "CORS Methods: " . $corsResponse->getHeaderLine('Access-Control-Allow-Methods') . "\n\n";

// 9. Paginated responses
echo "9. Paginated Responses:\n";

$paginatedResponse = ResponseFactory::paginated(
    [['id' => 1, 'name' => 'User 1'], ['id' => 2, 'name' => 'User 2']],
    100, // total
    1,   // current page
    10   // per page
);
echo "Paginated Response: " . $paginatedResponse->getBody() . "\n\n";

// 10. Request convenience methods
echo "10. Request Convenience Methods:\n";

$testRequest = RequestFactory::createForm('POST', '/test?search=query', [
    'username' => 'testuser',
    'password' => 'secret'
]);

echo "Is POST: " . ($testRequest->isPost() ? 'YES' : 'NO') . "\n";
echo "Get parameter 'search': " . $testRequest->getQuery('search') . "\n";
echo "Post parameter 'username': " . $testRequest->post('username') . "\n";
echo "Any parameter 'username': " . $testRequest->get('username') . "\n";
echo "Client IP: " . $testRequest->getClientIp() . "\n";
echo "Accepts JSON: " . ($testRequest->accepts('application/json') ? 'YES' : 'NO') . "\n\n";

echo "=== Demo Complete ===\n";