<?php
namespace HybridPHP\Core\Server;

use Workerman\Worker;

class WebsocketServer extends AbstractServer
{
    protected Worker $worker;

    public function __construct(string $listen = 'websocket://0.0.0.0:2346')
    {
        $this->worker = new Worker($listen);
        $this->worker->onMessage = [$this, 'onMessage'];
    }

    public function listen()
    {
        // Worker由ServerManager统一runAll
    }

    public function onMessage($connection, $data)
    {
        $connection->send('WebSocket response: ' . $data);
    }
}
