<?php

declare(strict_types=1);

/**
 * HybridPHP Notification System
 * 
 * Handles notifications for CI/CD pipeline events
 * Supports multiple notification channels: Slack, Discord, Email, Teams
 */
class NotificationManager
{
    private array $config;
    private array $channels = [];
    
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'slack' => [
                'webhook_url' => $_ENV['SLACK_WEBHOOK_URL'] ?? '',
                'channel' => '#ci-cd',
                'username' => 'HybridPHP CI/CD',
                'icon_emoji' => ':rocket:'
            ],
            'discord' => [
                'webhook_url' => $_ENV['DISCORD_WEBHOOK_URL'] ?? '',
                'username' => 'HybridPHP CI/CD'
            ],
            'teams' => [
                'webhook_url' => $_ENV['TEAMS_WEBHOOK_URL'] ?? ''
            ],
            'email' => [
                'smtp_host' => $_ENV['SMTP_HOST'] ?? '',
                'smtp_port' => $_ENV['SMTP_PORT'] ?? 587,
                'smtp_username' => $_ENV['SMTP_USERNAME'] ?? '',
                'smtp_password' => $_ENV['SMTP_PASSWORD'] ?? '',
                'from_email' => $_ENV['FROM_EMAIL'] ?? 'noreply@hybridphp.com',
                'to_emails' => explode(',', $_ENV['TO_EMAILS'] ?? '')
            ]
        ], $config);
        
        $this->initializeChannels();
    }
    
    private function initializeChannels(): void
    {
        if (!empty($this->config['slack']['webhook_url'])) {
            $this->channels[] = new SlackNotifier($this->config['slack']);
        }
        
        if (!empty($this->config['discord']['webhook_url'])) {
            $this->channels[] = new DiscordNotifier($this->config['discord']);
        }
        
        if (!empty($this->config['teams']['webhook_url'])) {
            $this->channels[] = new TeamsNotifier($this->config['teams']);
        }
        
        if (!empty($this->config['email']['smtp_host'])) {
            $this->channels[] = new EmailNotifier($this->config['email']);
        }
    }
    
    public function sendNotification(string $type, string $message, array $context = []): void
    {
        $notification = new Notification($type, $message, $context);
        
        foreach ($this->channels as $channel) {
            try {
                $channel->send($notification);
            } catch (Exception $e) {
                error_log("Failed to send notification via " . get_class($channel) . ": " . $e->getMessage());
            }
        }
    }
    
    public function sendDeploymentNotification(string $status, string $environment, array $details = []): void
    {
        $emoji = $this->getStatusEmoji($status);
        $color = $this->getStatusColor($status);
        
        $message = "{$emoji} Deployment {$status} for {$environment}";
        
        $context = array_merge([
            'environment' => $environment,
            'status' => $status,
            'color' => $color,
            'timestamp' => date('Y-m-d H:i:s'),
            'commit' => $_ENV['GITHUB_SHA'] ?? 'unknown',
            'branch' => $_ENV['GITHUB_REF_NAME'] ?? 'unknown',
            'actor' => $_ENV['GITHUB_ACTOR'] ?? 'unknown'
        ], $details);
        
        $this->sendNotification('deployment', $message, $context);
    }
    
    public function sendTestNotification(string $status, array $results = []): void
    {
        $emoji = $this->getStatusEmoji($status);
        $color = $this->getStatusColor($status);
        
        $message = "{$emoji} Test suite {$status}";
        
        $context = [
            'status' => $status,
            'color' => $color,
            'results' => $results,
            'timestamp' => date('Y-m-d H:i:s'),
            'commit' => $_ENV['GITHUB_SHA'] ?? 'unknown',
            'branch' => $_ENV['GITHUB_REF_NAME'] ?? 'unknown'
        ];
        
        $this->sendNotification('test', $message, $context);
    }
    
    public function sendSecurityAlert(string $severity, string $message, array $details = []): void
    {
        $emoji = $severity === 'high' ? '🚨' : ($severity === 'medium' ? '⚠️' : 'ℹ️');
        $color = $severity === 'high' ? '#ff0000' : ($severity === 'medium' ? '#ffaa00' : '#0099ff');
        
        $alertMessage = "{$emoji} Security Alert ({$severity}): {$message}";
        
        $context = array_merge([
            'severity' => $severity,
            'color' => $color,
            'timestamp' => date('Y-m-d H:i:s')
        ], $details);
        
        $this->sendNotification('security', $alertMessage, $context);
    }
    
    private function getStatusEmoji(string $status): string
    {
        return match($status) {
            'success', 'passed' => '✅',
            'failed', 'failure' => '❌',
            'warning' => '⚠️',
            'started', 'running' => '🚀',
            'cancelled' => '⏹️',
            default => 'ℹ️'
        };
    }
    
    private function getStatusColor(string $status): string
    {
        return match($status) {
            'success', 'passed' => '#00ff00',
            'failed', 'failure' => '#ff0000',
            'warning' => '#ffaa00',
            'started', 'running' => '#0099ff',
            'cancelled' => '#888888',
            default => '#cccccc'
        };
    }
}

class Notification
{
    public function __construct(
        public readonly string $type,
        public readonly string $message,
        public readonly array $context = []
    ) {}
}

interface NotifierInterface
{
    public function send(Notification $notification): void;
}

class SlackNotifier implements NotifierInterface
{
    private array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    public function send(Notification $notification): void
    {
        $payload = [
            'channel' => $this->config['channel'],
            'username' => $this->config['username'],
            'icon_emoji' => $this->config['icon_emoji'],
            'text' => $notification->message
        ];
        
        if ($notification->type === 'deployment') {
            $payload['attachments'] = [[
                'color' => $notification->context['color'] ?? '#cccccc',
                'fields' => [
                    [
                        'title' => 'Environment',
                        'value' => $notification->context['environment'] ?? 'unknown',
                        'short' => true
                    ],
                    [
                        'title' => 'Commit',
                        'value' => substr($notification->context['commit'] ?? 'unknown', 0, 8),
                        'short' => true
                    ],
                    [
                        'title' => 'Branch',
                        'value' => $notification->context['branch'] ?? 'unknown',
                        'short' => true
                    ],
                    [
                        'title' => 'Actor',
                        'value' => $notification->context['actor'] ?? 'unknown',
                        'short' => true
                    ]
                ],
                'ts' => time()
            ]];
        }
        
        $this->sendWebhook($payload);
    }
    
    private function sendWebhook(array $payload): void
    {
        $ch = curl_init($this->config['webhook_url']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode !== 200) {
            throw new Exception("Slack webhook failed with HTTP {$httpCode}: {$response}");
        }
        
        curl_close($ch);
    }
}

class DiscordNotifier implements NotifierInterface
{
    private array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    public function send(Notification $notification): void
    {
        $payload = [
            'username' => $this->config['username'],
            'content' => $notification->message
        ];
        
        if ($notification->type === 'deployment') {
            $payload['embeds'] = [[
                'color' => hexdec(ltrim($notification->context['color'] ?? '#cccccc', '#')),
                'fields' => [
                    [
                        'name' => 'Environment',
                        'value' => $notification->context['environment'] ?? 'unknown',
                        'inline' => true
                    ],
                    [
                        'name' => 'Commit',
                        'value' => substr($notification->context['commit'] ?? 'unknown', 0, 8),
                        'inline' => true
                    ],
                    [
                        'name' => 'Branch',
                        'value' => $notification->context['branch'] ?? 'unknown',
                        'inline' => true
                    ]
                ],
                'timestamp' => date('c')
            ]];
        }
        
        $this->sendWebhook($payload);
    }
    
    private function sendWebhook(array $payload): void
    {
        $ch = curl_init($this->config['webhook_url']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode !== 204) {
            throw new Exception("Discord webhook failed with HTTP {$httpCode}: {$response}");
        }
        
        curl_close($ch);
    }
}

class TeamsNotifier implements NotifierInterface
{
    private array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    public function send(Notification $notification): void
    {
        $payload = [
            '@type' => 'MessageCard',
            '@context' => 'http://schema.org/extensions',
            'summary' => $notification->message,
            'themeColor' => ltrim($notification->context['color'] ?? '#cccccc', '#'),
            'sections' => [[
                'activityTitle' => $notification->message,
                'activitySubtitle' => 'HybridPHP CI/CD Pipeline',
                'facts' => []
            ]]
        ];
        
        if ($notification->type === 'deployment') {
            $payload['sections'][0]['facts'] = [
                ['name' => 'Environment', 'value' => $notification->context['environment'] ?? 'unknown'],
                ['name' => 'Commit', 'value' => substr($notification->context['commit'] ?? 'unknown', 0, 8)],
                ['name' => 'Branch', 'value' => $notification->context['branch'] ?? 'unknown'],
                ['name' => 'Actor', 'value' => $notification->context['actor'] ?? 'unknown']
            ];
        }
        
        $this->sendWebhook($payload);
    }
    
    private function sendWebhook(array $payload): void
    {
        $ch = curl_init($this->config['webhook_url']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode !== 200) {
            throw new Exception("Teams webhook failed with HTTP {$httpCode}: {$response}");
        }
        
        curl_close($ch);
    }
}

class EmailNotifier implements NotifierInterface
{
    private array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    public function send(Notification $notification): void
    {
        $subject = "HybridPHP CI/CD: " . $notification->message;
        $body = $this->buildEmailBody($notification);
        
        foreach ($this->config['to_emails'] as $email) {
            if (!empty(trim($email))) {
                $this->sendEmail(trim($email), $subject, $body);
            }
        }
    }
    
    private function buildEmailBody(Notification $notification): string
    {
        $html = "<html><body>";
        $html .= "<h2>HybridPHP CI/CD Notification</h2>";
        $html .= "<p><strong>Message:</strong> " . htmlspecialchars($notification->message) . "</p>";
        $html .= "<p><strong>Type:</strong> " . htmlspecialchars($notification->type) . "</p>";
        $html .= "<p><strong>Timestamp:</strong> " . date('Y-m-d H:i:s') . "</p>";
        
        if (!empty($notification->context)) {
            $html .= "<h3>Details:</h3><ul>";
            foreach ($notification->context as $key => $value) {
                if (is_scalar($value)) {
                    $html .= "<li><strong>" . htmlspecialchars($key) . ":</strong> " . htmlspecialchars($value) . "</li>";
                }
            }
            $html .= "</ul>";
        }
        
        $html .= "</body></html>";
        
        return $html;
    }
    
    private function sendEmail(string $to, string $subject, string $body): void
    {
        // Simple SMTP implementation - in production, use a proper email library
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . $this->config['from_email'],
            'Reply-To: ' . $this->config['from_email'],
            'X-Mailer: HybridPHP CI/CD'
        ];
        
        if (!mail($to, $subject, $body, implode("\r\n", $headers))) {
            throw new Exception("Failed to send email to {$to}");
        }
    }
}

// CLI usage
if (php_sapi_name() === 'cli') {
    $options = getopt('t:m:e:s:', ['type:', 'message:', 'environment:', 'status:']);
    
    if (empty($options['type']) || empty($options['message'])) {
        echo "Usage: php notify.php -t <type> -m <message> [-e <environment>] [-s <status>]\n";
        echo "Types: deployment, test, security\n";
        exit(1);
    }
    
    $notifier = new NotificationManager();
    
    switch ($options['type']) {
        case 'deployment':
            $notifier->sendDeploymentNotification(
                $options['status'] ?? 'unknown',
                $options['environment'] ?? 'unknown'
            );
            break;
            
        case 'test':
            $notifier->sendTestNotification($options['status'] ?? 'unknown');
            break;
            
        case 'security':
            $notifier->sendSecurityAlert(
                $options['status'] ?? 'medium',
                $options['message']
            );
            break;
            
        default:
            $notifier->sendNotification($options['type'], $options['message']);
            break;
    }
    
    echo "Notification sent successfully\n";
}