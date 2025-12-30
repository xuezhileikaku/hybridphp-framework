<?php
namespace HybridPHP\Core;

use Workerman\Events\EventInterface;
use Workerman\Events\Select;

class EventLoop
{
    protected EventInterface $loop;

    public function __construct()
    {
        $this->loop = new Select();
    }

    public function addReadStream($fd, callable $listener)
    {
        $this->loop->add($fd, EventInterface::EV_READ, $listener);
    }

    public function addWriteStream($fd, callable $listener)
    {
        $this->loop->add($fd, EventInterface::EV_WRITE, $listener);
    }

    public function run()
    {
        $this->loop->loop();
    }
}
