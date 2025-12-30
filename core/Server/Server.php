<?php
namespace HybridPHP\Core\Server;

use HybridPHP\Core\Container;
use HybridPHP\Core\EventLoop;
use HybridPHP\Core\Process\Manager;
use Workerman\Worker;

class Server
{
    protected array $servers;
    protected Container $container;
    protected EventLoop $eventLoop;
    protected Manager $processManager;

    public function __construct(array $servers, Container $container, EventLoop $eventLoop)
    {
        $this->servers = $servers;
        $this->container = $container;
        $this->eventLoop = $eventLoop;
        $this->processManager = new Manager($container);
    }

    public function start(): void
    {
        foreach ($this->servers as $type => $config) {
            $this->createServer($type, $config);
        }
        
        $this->processManager->start();
    }

    protected function createServer(string $type, array $config): void
    {
        $host = $config['host'] ?? '0.0.0.0';
        $port = $config['port'];
        $workerNum = $config['worker_num'] ?? 1;
        
        switch ($type) {
            case 'http':
                $worker = new Worker("http://{$host}:{$port}");
                $worker->count = $workerNum;
                
                $httpServer = new HttpServer($config, $this->container);
                $httpServer->on('worker.start', function ($worker) {
                    $this->container->set('http.worker', $worker);
                });
                
                $this->processManager->addWorker('http', $worker);
                break;
                
            case 'websocket':
                $worker = new Worker("websocket://{$host}:{$port}");
                $worker->count = $workerNum;
                
                $wsServer = new WebsocketServer($config, $this->container);
                $wsServer->on('worker.start', function ($worker) {
                    $this->container->set('websocket.worker', $worker);
                });
                
                $this->processManager->addWorker('websocket', $worker);
                break;
                
            case 'tcp':
                $worker = new Worker("tcp://{$host}:{$port}");
                $worker->count = $workerNum;
                $this->processManager->addWorker('tcp', $worker);
                break;
                
            case 'udp':
                $worker = new Worker("udp://{$host}:{$port}");
                $worker->count = $workerNum;
                $this->processManager->addWorker('udp', $worker);
                break;
                
            default:
                throw new \Exception("Unsupported server type: {$type}");
        }
    }

    public function getProcessManager(): Manager
    {
        return $this->processManager;
    }

    public function addCustomServer(string $name, Worker $worker): void
    {
        $this->processManager->addWorker($name, $worker);
    }
}
