<?php

declare(strict_types=1);

namespace HybridPHP\Core\Health\Checks;

use HybridPHP\Core\Health\AbstractHealthCheck;
use HybridPHP\Core\Health\HealthCheckResult;
use Amp\Future;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Psr\Log\LoggerInterface;
use function Amp\async;

/**
 * External service health check
 */
class ExternalServiceHealthCheck extends AbstractHealthCheck
{
    private string $url;
    private array $headers;
    private int $expectedStatus;
    private ?string $expectedContent;
    private HttpClient $httpClient;

    public function __construct(
        string $name,
        string $url,
        HttpClient $httpClient,
        array $headers = [],
        int $expectedStatus = 200,
        ?string $expectedContent = null,
        ?LoggerInterface $logger = null,
        int $timeout = 10,
        bool $critical = false
    ) {
        parent::__construct($name, $timeout, $critical, $logger);
        $this->url = $url;
        $this->headers = $headers;
        $this->expectedStatus = $expectedStatus;
        $this->expectedContent = $expectedContent;
        $this->httpClient = $httpClient;
    }

    protected function performCheck(): Future
    {
        return async(function () {
            try {
                $request = new Request($this->url, 'GET');

                // Add custom headers
                foreach ($this->headers as $name => $value) {
                    $request = $request->withHeader($name, $value);
                }

                // Add timeout
                $request = $request->withTimeout($this->timeout * 1000);

                /** @var Response $response */
                $response = $this->httpClient->request($request)->await();

                $statusCode = $response->getStatus();
                $body = $response->getBody()->buffer()->await();

                $data = [
                    'url' => $this->url,
                    'status_code' => $statusCode,
                    'expected_status' => $this->expectedStatus,
                    'response_size' => strlen($body),
                    'headers' => $response->getHeaders(),
                ];

                // Check status code
                if ($statusCode !== $this->expectedStatus) {
                    return HealthCheckResult::unhealthy(
                        $this->name,
                        "Unexpected status code: {$statusCode}, expected: {$this->expectedStatus}",
                        $data
                    );
                }

                // Check content if specified
                if ($this->expectedContent !== null && strpos($body, $this->expectedContent) === false) {
                    return HealthCheckResult::unhealthy(
                        $this->name,
                        "Expected content not found in response",
                        $data
                    );
                }

                return HealthCheckResult::healthy(
                    $this->name,
                    "External service is responding correctly",
                    $data
                );

            } catch (\Throwable $e) {
                return HealthCheckResult::unhealthy(
                    $this->name,
                    'External service check failed: ' . $e->getMessage(),
                    ['url' => $this->url],
                    0.0,
                    $e
                );
            }
        });
    }
}