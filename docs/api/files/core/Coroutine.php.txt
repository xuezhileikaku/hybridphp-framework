<?php
namespace HybridPHP\Core;

use Amp\Future;
use function Amp\async;

class Coroutine
{
    public static function create(callable $fn, ...$args): Future
    {
        return async(fn() => $fn(...$args));
    }

    public static function await(Future $future)
    {
        return $future->await();
    }
}
