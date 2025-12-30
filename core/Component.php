<?php
namespace HybridPHP\Core;

class Component
{
    public function init()
    {
        // For child class initialization
    }

    // 简化属�?getter/setter，可�?__get/__set 魔术方法
    public function __get($name)
    {
        return $this->$name ?? null;
    }

    public function __set($name, $value)
    {
        $this->$name = $value;
    }
}
