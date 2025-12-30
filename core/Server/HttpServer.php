<?php
namespace HybridPHP\Core\Server;

use Workerman\Worker;

class HttpServer extends AbstractServer
{
    protected Worker $worker;

    public function __construct(string $listen = 'http://0.0.0.0:8080')
    {
        $this->worker = new Worker($listen);
        $this->worker->onMessage = [$this, 'onMessage'];
    }

    public function listen()
    {
        // Workerman �?Worker 会在 Worker::runAll() 统一启动
    }

    public function onMessage($connection, $request)
    {
        $connection->send('Hello, LaboFrame!');
    }
}
