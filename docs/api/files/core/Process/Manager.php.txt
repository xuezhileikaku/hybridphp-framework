<?php
namespace HybridPHP\Core\Process;

use Workerman\Worker;
use HybridPHP\Core\EventEmitter;
use HybridPHP\Core\Container;

class Manager
{
    protected array $workers = [];
    protected EventEmitter $events;
    protected Container $container;
    protected bool $running = false;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->events = new EventEmitter();
    }

    public function addWorker(string $name, Worker $worker, array $config = []): void
    {
        $this->workers[$name] = [
            'worker' => $worker,
            'config' => $config
        ];

        $this->setupWorkerEvents($worker, $name);
    }

    protected function setupWorkerEvents(Worker $worker, string $name): void
    {
        $worker->onWorkerStart = function (Worker $worker) use ($name) {
            $this->events->emit('worker.start', $name, $worker);
            $this->container->set("worker.{$name}", $worker);
        };

        $worker->onWorkerStop = function (Worker $worker) use ($name) {
            $this->events->emit('worker.stop', $name, $worker);
        };

        $worker->onWorkerReload = function (Worker $worker) use ($name) {
            $this->events->emit('worker.reload', $name, $worker);
        };
    }

    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;
        $this->events->emit('manager.start', $this);

        // 设置全局事件
        Worker::$onMasterStart = function () {
            $this->events->emit('master.start');
        };

        Worker::$onMasterStop = function () {
            $this->events->emit('master.stop');
        };

        Worker::runAll();
    }

    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        $this->events->emit('manager.stop', $this);
        
        foreach ($this->workers as $name => $workerData) {
            $workerData['worker']->stop();
        }
        
        $this->running = false;
    }

    public function reload(): void
    {
        $this->events->emit('manager.reload', $this);
        
        foreach ($this->workers as $name => $workerData) {
            $workerData['worker']->reload();
        }
    }

    public function getWorker(string $name): ?Worker
    {
        return $this->workers[$name]['worker'] ?? null;
    }

    public function getWorkers(): array
    {
        return $this->workers;
    }

    public function on(string $event, callable $listener): void
    {
        $this->events->on($event, $listener);
    }

    public function status(): array
    {
        $status = [];
        foreach ($this->workers as $name => $workerData) {
            $worker = $workerData['worker'];
            $status[$name] = [
                'name' => $name,
                'status' => $worker->status,
                'connections' => count($worker->connections),
                'worker_id' => $worker->id,
                'worker_pid' => $worker->pid,
            ];
        }
        return $status;
    }
}
