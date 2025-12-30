<?php
namespace HybridPHP\Core;

interface LoggerInterface
{
    public function log(string $level, string $message, array $context = []): void;
}
