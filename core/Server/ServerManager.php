<?php
namespace HybridPHP\Core\Server;

use HybridPHP\Core\Container;
use HybridPHP\Core\EventEmitter;
use Workerman\Worker;
use Amp\Future;
use function Amp\async;
use function Amp\delay;

class ServerManager
{
    protected array $servers;
    protected Container $container;
    protected ?EventEmitter $event;
    protected bool $started = false;

    public function __construct(array $servers, Container $container, ?EventEmitter $event = null)
    {
        $this->servers = $servers;
        $this->container = $container;
        $this->event = $event;
    }

    /**
     * Start all servers
     */
    public function startAll(): void
    {
        if ($this->started) {
            return;
        }

        foreach ($this->servers as $server) {
            if ($server instanceof AbstractServer) {
                $server->listen();
            }
        }
        
        $this->started = true;
        
        if ($this->event) {
            $this->event->emit('servers.started', [$this->servers]);
        }
        
        // Start Workerman if servers are configured
        if (!empty($this->servers)) {
            Worker::runAll();
        }
    }

    /**
     * Stop all servers
     */
    public function stopAll(): void
    {
        foreach ($this->servers as $server) {
            if ($server instanceof AbstractServer && method_exists($server, 'stop')) {
                $server->stop();
            }
        }
        
        $this->started = false;
        
        if ($this->event) {
            $this->event->emit('servers.stopped', [$this->servers]);
        }
    }

    /**
     * Stop all servers asynchronously
     */
    public function stopAllAsync(): Future
    {
        return async(function() {
            foreach ($this->servers as $server) {
                if ($server instanceof AbstractServer) {
                    if (method_exists($server, 'stopAsync')) {
                        $server->stopAsync();
                    } elseif (method_exists($server, 'stop')) {
                        $server->stop();
                        delay(0.1); // Small delay for graceful shutdown
                    }
                }
            }
            
            $this->started = false;
            
            if ($this->event) {
                $this->event->emit('servers.stopped', [$this->servers]);
            }
        });
    }

    /**
     * Restart all servers
     */
    public function restart(): void
    {
        $this->stopAll();
        
        // Small delay before restart
        usleep(500000); // 0.5 seconds
        
        $this->startAll();
        
        if ($this->event) {
            $this->event->emit('servers.restarted', [$this->servers]);
        }
    }

    /**
     * Restart all servers asynchronously
     */
    public function restartAsync(): Future
    {
        return async(function() {
            $this->stopAllAsync();
            
            // Delay before restart
            delay(0.5);
            
            $this->startAll();
            
            if ($this->event) {
                $this->event->emit('servers.restarted', [$this->servers]);
            }
        });
    }

    /**
     * Check health of all servers
     */
    public function checkHealth(): Future
    {
        return async(function() {
            $health = [];
            
            foreach ($this->servers as $index => $server) {
                $serverHealth = [
                    'name' => get_class($server),
                    'status' => 'unknown',
                    'uptime' => 0,
                    'connections' => 0
                ];
                
                if ($server instanceof AbstractServer) {
                    if (method_exists($server, 'checkHealth')) {
                        $serverHealth = $server->checkHealth();
                    } else {
                        $serverHealth['status'] = $this->started ? 'running' : 'stopped';
                    }
                }
                
                $health[$index] = $serverHealth;
            }
            
            return $health;
        });
    }

    /**
     * Get server statistics
     */
    public function getStats(): array
    {
        $stats = [
            'total_servers' => count($this->servers),
            'started' => $this->started,
            'servers' => []
        ];
        
        foreach ($this->servers as $index => $server) {
            $serverStats = [
                'class' => get_class($server),
                'status' => $this->started ? 'running' : 'stopped'
            ];
            
            if ($server instanceof AbstractServer && method_exists($server, 'getStats')) {
                $serverStats = array_merge($serverStats, $server->getStats());
            }
            
            $stats['servers'][$index] = $serverStats;
        }
        
        return $stats;
    }

    /**
     * Add a server
     */
    public function addServer(AbstractServer $server): void
    {
        $this->servers[] = $server;
        
        if ($this->event) {
            $this->event->emit('server.added', [$server]);
        }
    }

    /**
     * Remove a server
     */
    public function removeServer(int $index): bool
    {
        if (!isset($this->servers[$index])) {
            return false;
        }
        
        $server = $this->servers[$index];
        
        if ($server instanceof AbstractServer && method_exists($server, 'stop')) {
            $server->stop();
        }
        
        unset($this->servers[$index]);
        $this->servers = array_values($this->servers); // Re-index array
        
        if ($this->event) {
            $this->event->emit('server.removed', [$server]);
        }
        
        return true;
    }

    /**
     * Get all servers
     */
    public function getServers(): array
    {
        return $this->servers;
    }

    /**
     * Check if servers are started
     */
    public function isStarted(): bool
    {
        return $this->started;
    }
}
