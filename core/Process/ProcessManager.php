<?php
namespace HybridPHP\Core\Process;

use HybridPHP\Core\EventLoop;

class ProcessManager {
    protected array $workers = [];
    protected EventLoop $eventLoop;
    protected bool $isRunning = false;
    protected bool $supportPcntl = false; // 新增属�?

    public function __construct(EventLoop $eventLoop)
    {
        $this->eventLoop = $eventLoop;
        $this->supportPcntl = function_exists('pcntl_fork');
    }

    public function on(string $event, callable $listener): void
    {
        $this->eventLoop->on($event, $listener);
    }

    public function emit(string $event, array $data = []): void
    {
        $this->eventLoop->emit($event, $data);
    }

    public function createWorker(callable $workerFunc, string $name = 'worker'): int
    {
        if (!$this->supportPcntl) {
            // Windows/无pcntl环境，直接主进程同步执行
            echo "[WARN] PCNTL not available. Running worker '{$name}' in main process (single process mode)\n";
            try {
                call_user_func($workerFunc);
            } catch (\Throwable $e) {
                error_log("Worker {$name} error: " . $e->getMessage());
            }
            return 0; // 返回0，模拟主进程pid
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new \RuntimeException('Could not fork worker process');
        }
        if ($pid === 0) {
            // Child process
            try {
                $this->setupChildProcess($name);
                call_user_func($workerFunc);
            } catch (\Throwable $e) {
                error_log("Worker {$name} error: " . $e->getMessage());
                exit(1);
            }
            exit(0);
        }
        // Parent process
        $worker = [
            'pid' => $pid,
            'name' => $name,
            'started' => time(),
            'status' => 'running'
        ];
        $this->workers[$pid] = $worker;
        $this->emit('worker.start', [$name, $worker]);
        return $pid;
    }

    protected function setupChildProcess(string $name): void
    {
        if (function_exists('cli_set_process_title')) {
            cli_set_process_title("hybridphp-worker: {$name}");
        }
        if ($this->supportPcntl) {
            pcntl_signal(SIGTERM, [$this, 'handleChildSignal']);
            pcntl_signal(SIGINT, [$this, 'handleChildSignal']);
        }
    }

    protected function handleChildSignal(int $signal): void
    {
        switch ($signal) {
            case SIGTERM:
            case SIGINT:
                exit(0);
        }
    }

    public function stopAllWorkers(): void
    {
        if (!$this->supportPcntl) {
            echo "[WARN] PCNTL not available. stopAllWorkers is no-op in single process mode\n";
            return;
        }
        foreach ($this->workers as $pid => $worker) {
            posix_kill($pid, SIGTERM);
            $this->emit('worker.stop', [$worker['name'], $worker]);
        }
        // 等待所有子进程结束
        $timeout = 10;
        $start = time();
        while (!empty($this->workers) && (time() - $start) < $timeout) {
            foreach ($this->workers as $pid => $worker) {
                $result = pcntl_waitpid($pid, $status, WNOHANG);
                if ($result === $pid || $result === -1) {
                    unset($this->workers[$pid]);
                }
            }
            usleep(100000);
        }
        foreach ($this->workers as $pid => $worker) {
            posix_kill($pid, SIGKILL);
        }
        $this->workers = [];
    }

    public function reloadWorkers(): void
    {
        if (!$this->supportPcntl) {
            echo "[WARN] PCNTL not available. reloadWorkers is no-op in single process mode\n";
            return;
        }
        foreach ($this->workers as $pid => $worker) {
            posix_kill($pid, SIGUSR1);
            $this->emit('worker.reload', [$worker['name'], $worker]);
        }
    }

    public function getWorkerStats(): array
    {
        return [
            'total' => count($this->workers),
            'running' => count(array_filter($this->workers, fn($w) => $w['status'] === 'running')),
            'workers' => $this->workers
        ];
    }

    public function monitorWorkers(): void
    {
        if (!$this->supportPcntl || empty($this->workers)) {
            return;
        }
        foreach ($this->workers as $pid => $worker) {
            $result = pcntl_waitpid($pid, $status, WNOHANG);
            if ($result === $pid) {
                $worker['status'] = 'exited';
                $worker['exit_code'] = pcntl_wexitstatus($status);
                $this->workers[$pid] = $worker;
                $this->emit('worker.exit', [$worker['name'], $worker]);
            }
        }
    }

    public function isRunning(): bool
    {
        return $this->isRunning;
    }

    public function setRunning(bool $running): void
    {
        $this->isRunning = $running;
    }
}
