<?php
namespace HybridPHP\Core\Server;

abstract class AbstractServer
{
    abstract public function listen();

    public function stop()
    {
        // 可选扩展：停止服务
    }
}
