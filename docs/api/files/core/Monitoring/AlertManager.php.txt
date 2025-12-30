<?php

declare(strict_types=1);

namespace HybridPHP\Core\Monitoring;

use Amp\Future;
use Psr\Log\LoggerInterface;
use function Amp\async;
use function Amp\delay;

/**
 * Alert management system
 */
class AlertManager
{
    private array $alerts = [];
    private array $rules = [];
    private array $notifiers = [];
    private ?LoggerInterface $logger;
    private array $config;
    private bool $processing = false;

    public function __construct(?LoggerInterface $logger = null, array $config = [])
    {
        $this->logger = $logger;
        $this->config = array_merge([
            'processing_interval' => 10, // seconds
            'alert_retention' => 3600, // 1 hour
            'max_alerts' => 1000,
            'cooldown_period' => 300, // 5 minutes
        ], $config);
    }

    /**
     * Start alert processing
     */
    public function start(): Future
    {
        return async(function () {
            if ($this->processing) {
                return;
            }

            $this->processing = true;
            
            if ($this->logger) {
                $this->logger->info('Alert manager started');
            }

            while ($this->processing) {
                try {
                    $this->processAlerts();
                    $this->cleanupOldAlerts();
                } catch (\Throwable $e) {
                    if ($this->logger) {
                        $this->logger->error('Alert processing failed', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                delay($this->config['processing_interval']);
            }
        });
    }

    /**
     * Stop alert processing
     */
    public function stop(): void
    {
        $this->processing = false;
        
        if ($this->logger) {
            $this->logger->info('Alert manager stopped');
        }
    }

    /**
     * Trigger an alert
     */
    public function trigger(string $name, array $data = [], string $severity = 'warning'): void
    {
        $alertId = $this->generateAlertId($name, $data);
        $now = time();
        
        // Check if alert is in cooldown
        if (isset($this->alerts[$alertId])) {
            $lastTriggered = $this->alerts[$alertId]['last_triggered'];
            if ($now - $lastTriggered < $this->config['cooldown_period']) {
                return; // Skip duplicate alert in cooldown period
            }
        }

        $alert = [
            'id' => $alertId,
            'name' => $name,
            'severity' => $severity,
            'data' => $data,
            'first_triggered' => $this->alerts[$alertId]['first_triggered'] ?? $now,
            'last_triggered' => $now,
            'count' => ($this->alerts[$alertId]['count'] ?? 0) + 1,
            'status' => 'active',
        ];

        $this->alerts[$alertId] = $alert;

        if ($this->logger) {
            $this->logger->log($this->getSeverityLogLevel($severity), "Alert triggered: {$name}", [
                'alert_id' => $alertId,
                'severity' => $severity,
                'data' => $data,
                'count' => $alert['count'],
            ]);
        }

        // Send notifications
        $this->sendNotifications($alert);
    }

    /**
     * Resolve an alert
     */
    public function resolve(string $name, array $data = []): void
    {
        $alertId = $this->generateAlertId($name, $data);
        
        if (isset($this->alerts[$alertId])) {
            $this->alerts[$alertId]['status'] = 'resolved';
            $this->alerts[$alertId]['resolved_at'] = time();
            
            if ($this->logger) {
                $this->logger->info("Alert resolved: {$name}", [
                    'alert_id' => $alertId,
                ]);
            }

            // Send resolution notifications
            $this->sendNotifications($this->alerts[$alertId], true);
        }
    }

    /**
     * Add alert rule
     */
    public function addRule(string $name, callable $condition, array $config = []): void
    {
        $this->rules[$name] = [
            'condition' => $condition,
            'config' => array_merge([
                'severity' => 'warning',
                'cooldown' => $this->config['cooldown_period'],
            ], $config),
        ];
    }

    /**
     * Add notification handler
     */
    public function addNotifier(string $name, callable $handler, array $config = []): void
    {
        $this->notifiers[$name] = [
            'handler' => $handler,
            'config' => array_merge([
                'enabled' => true,
                'severities' => ['critical', 'warning', 'info'],
            ], $config),
        ];
    }

    /**
     * Get active alerts
     */
    public function getActiveAlerts(): array
    {
        return array_filter($this->alerts, function ($alert) {
            return $alert['status'] === 'active';
        });
    }

    /**
     * Get all alerts
     */
    public function getAllAlerts(): array
    {
        return $this->alerts;
    }

    /**
     * Get alert statistics
     */
    public function getStatistics(): array
    {
        $stats = [
            'total' => count($this->alerts),
            'active' => 0,
            'resolved' => 0,
            'by_severity' => [],
            'by_name' => [],
        ];

        foreach ($this->alerts as $alert) {
            if ($alert['status'] === 'active') {
                $stats['active']++;
            } else {
                $stats['resolved']++;
            }

            $severity = $alert['severity'];
            $stats['by_severity'][$severity] = ($stats['by_severity'][$severity] ?? 0) + 1;

            $name = $alert['name'];
            $stats['by_name'][$name] = ($stats['by_name'][$name] ?? 0) + 1;
        }

        return $stats;
    }

    /**
     * Clear all alerts
     */
    public function clear(): void
    {
        $this->alerts = [];
        
        if ($this->logger) {
            $this->logger->info('All alerts cleared');
        }
    }

    /**
     * Process alerts and check rules
     */
    private function processAlerts(): void
    {
        foreach ($this->rules as $name => $rule) {
            try {
                $condition = $rule['condition'];
                $result = $condition();
                
                if ($result === true) {
                    $this->trigger($name, [], $rule['config']['severity']);
                } elseif (is_array($result)) {
                    $this->trigger($name, $result, $rule['config']['severity']);
                }
            } catch (\Throwable $e) {
                if ($this->logger) {
                    $this->logger->error("Alert rule '{$name}' failed", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Send notifications for alert
     */
    private function sendNotifications(array $alert, bool $resolved = false): void
    {
        foreach ($this->notifiers as $name => $notifier) {
            if (!$notifier['config']['enabled']) {
                continue;
            }

            if (!in_array($alert['severity'], $notifier['config']['severities'])) {
                continue;
            }

            try {
                $handler = $notifier['handler'];
                $handler($alert, $resolved);
            } catch (\Throwable $e) {
                if ($this->logger) {
                    $this->logger->error("Notification handler '{$name}' failed", [
                        'alert_id' => $alert['id'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Clean up old alerts
     */
    private function cleanupOldAlerts(): void
    {
        $cutoff = time() - $this->config['alert_retention'];
        $removed = 0;

        foreach ($this->alerts as $id => $alert) {
            if ($alert['status'] === 'resolved' && 
                isset($alert['resolved_at']) && 
                $alert['resolved_at'] < $cutoff) {
                unset($this->alerts[$id]);
                $removed++;
            }
        }

        // Also limit total number of alerts
        if (count($this->alerts) > $this->config['max_alerts']) {
            // Keep only the most recent alerts
            uasort($this->alerts, function ($a, $b) {
                return $b['last_triggered'] - $a['last_triggered'];
            });

            $this->alerts = array_slice($this->alerts, 0, $this->config['max_alerts'], true);
        }

        if ($removed > 0 && $this->logger) {
            $this->logger->debug("Cleaned up {$removed} old alerts");
        }
    }

    /**
     * Generate unique alert ID
     */
    private function generateAlertId(string $name, array $data): string
    {
        return md5($name . serialize($data));
    }

    /**
     * Get log level for severity
     */
    private function getSeverityLogLevel(string $severity): string
    {
        switch ($severity) {
            case 'critical':
                return 'critical';
            case 'warning':
                return 'warning';
            case 'info':
                return 'info';
            default:
                return 'notice';
        }
    }
}

/**
 * Built-in notification handlers
 */
class NotificationHandlers
{
    /**
     * Log notification handler
     */
    public static function logHandler(LoggerInterface $logger): callable
    {
        return function (array $alert, bool $resolved = false) use ($logger) {
            $message = $resolved ? "Alert resolved: {$alert['name']}" : "Alert: {$alert['name']}";
            
            $logger->log(
                $resolved ? 'info' : 'warning',
                $message,
                [
                    'alert_id' => $alert['id'],
                    'severity' => $alert['severity'],
                    'data' => $alert['data'],
                    'count' => $alert['count'],
                    'resolved' => $resolved,
                ]
            );
        };
    }

    /**
     * Email notification handler (placeholder)
     */
    public static function emailHandler(array $config): callable
    {
        return function (array $alert, bool $resolved = false) use ($config) {
            // This would integrate with an email service
            // For now, just a placeholder
            error_log("EMAIL ALERT: " . ($resolved ? 'RESOLVED' : 'TRIGGERED') . " - {$alert['name']}");
        };
    }

    /**
     * Webhook notification handler
     */
    public static function webhookHandler(string $url, array $headers = []): callable
    {
        return function (array $alert, bool $resolved = false) use ($url, $headers) {
            $payload = [
                'alert' => $alert,
                'resolved' => $resolved,
                'timestamp' => date('c'),
            ];

            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => array_merge([
                        'Content-Type: application/json',
                    ], $headers),
                    'content' => json_encode($payload),
                ],
            ]);

            // Non-blocking webhook call
            @file_get_contents($url, false, $context);
        };
    }

    /**
     * Slack notification handler (placeholder)
     */
    public static function slackHandler(string $webhookUrl): callable
    {
        return function (array $alert, bool $resolved = false) use ($webhookUrl) {
            $color = $resolved ? 'good' : ($alert['severity'] === 'critical' ? 'danger' : 'warning');
            $title = $resolved ? "Alert Resolved: {$alert['name']}" : "Alert: {$alert['name']}";
            
            $payload = [
                'attachments' => [
                    [
                        'color' => $color,
                        'title' => $title,
                        'fields' => [
                            [
                                'title' => 'Severity',
                                'value' => $alert['severity'],
                                'short' => true,
                            ],
                            [
                                'title' => 'Count',
                                'value' => $alert['count'],
                                'short' => true,
                            ],
                        ],
                        'timestamp' => $alert['last_triggered'],
                    ],
                ],
            ];

            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-Type: application/json',
                    'content' => json_encode($payload),
                ],
            ]);

            @file_get_contents($webhookUrl, false, $context);
        };
    }
}