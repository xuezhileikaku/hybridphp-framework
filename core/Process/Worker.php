<?php
namespace HybridPHP\Core\Process;

use Workerman\Worker as WorkermanWorker;
use HybridPHP\Core\Container;
use HybridPHP\Core\EventEmitter;

class Worker
{
    protected WorkermanWorker $worker;
    protected Container $container;
    protected EventEmitter $events;
    protected array $config;

    public function __construct(string $socket, array $config = [])
    {
        $this->worker = new WorkermanWorker($socket);
        $this->config = array_merge($this->defaultConfig(), $config);
        $this->events = new EventEmitter();
        
        $this->configureWorker();
    }

    protected function defaultConfig(): array
    {
        return [
            'name' => 'Worker',
            'count' => 1,
            'user' => '',
            'group' => '',
            'reloadable' => true,
            'reusePort' => false,
        ];
    }

    protected function configureWorker(): void
    {
        $this->worker->name = $this->config['name'];
        $this->worker->count = $this->config['count'];
        
        if ($this->config['user']) {
            $this->worker->user = $this->config['user'];
        }
        
        if ($this->config['group']) {
            $this->worker->group = $this->config['group'];
        }
        
        $this->worker->reloadable = $this->config['reloadable'];
        $this->worker->reusePort = $this->config['reusePort'];
    }

    public function getWorker(): WorkermanWorker
    {
        return $this->worker;
    }

    public function setContainer(Container $container): void
    {
        $this->container = $container;
    }

    public function onWorkerStart(callable $callback): void
    {
        $this->worker->onWorkerStart = function (WorkermanWorker $worker) use ($callback) {
            $this->events->emit('worker.start', $worker);
            $callback($worker, $this->container);
        };
    }

    public function onWorkerStop(callable $callback): void
    {
        $this->worker->onWorkerStop = function (WorkermanWorker $worker) use ($callback) {
            $this->events->emit('worker.stop', $worker);
            $callback($worker, $this->container);
        };
    }

    public function onWorkerReload(callable $callback): void
    {
        $this->worker->onWorkerReload = function (WorkermanWorker $worker) use ($callback) {
            $this->events->emit('worker.reload', $worker);
            $callback($worker, $this->container);
        };
    }

    public function onConnect(callable $callback): void
    {
        $this->worker->onConnect = function ($connection) use ($callback) {
            $this->events->emit('connection.connect', $connection);
            $callback($connection, $this->container);
        };
    }

    public function onClose(callable $callback): void
    {
        $this->worker->onClose = function ($connection) use ($callback) {
            $this->events->emit('connection.close', $connection);
            $callback($connection, $this->container);
        };
    }

    public function onMessage(callable $callback): void
    {
        $this->worker->onMessage = function ($connection, $data) use ($callback) {
            $this->events->emit('message.received', $connection, $data);
            $callback($connection, $data, $this->container);
        };
    }

    public function on(string $event, callable $listener): void
    {
        $this->events->on($event, $listener);
    }

    public function start(): void
    {
        WorkermanWorker::runAll();
    }
}
