<?php
namespace HybridPHP\Core;

class Message
{
    protected array $headers = [];
    protected $body;

    public function __construct($body = '', array $headers = [])
    {
        $this->body = $body;
        $this->headers = $headers;
    }

    public function getHeader(string $key)
    {
        return $this->headers[$key] ?? null;
    }

    public function setHeader(string $key, $value)
    {
        $this->headers[$key] = $value;
    }

    public function getBody()
    {
        return $this->body;
    }

    public function setBody($body)
    {
        $this->body = $body;
    }
}
