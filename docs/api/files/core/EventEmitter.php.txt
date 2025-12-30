<?php
namespace HybridPHP\Core;

class EventEmitter
{
    protected array $listeners = [];

    public function on(string $event, callable $listener)
    {
        $this->listeners[$event][] = $listener;
    }

    public function off(string $event, callable $listener)
    {
        if (!isset($this->listeners[$event])) return;
        foreach ($this->listeners[$event] as $i => $cb) {
            if ($cb === $listener) {
                unset($this->listeners[$event][$i]);
            }
        }
    }

    public function emit(string $event, ...$args)
    {
        foreach ($this->listeners[$event] ?? [] as $cb) {
            call_user_func_array($cb, $args);
        }
    }
}
