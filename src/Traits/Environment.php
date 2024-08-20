<?php

namespace AlexFN\NanoService\Traits;

trait Environment
{
    protected $config = [];

    protected $prefix = 'AMQP_';

    protected function getEnv(string $param): ?string
    {
        $configParam = strtolower(substr($param, strlen($this->prefix)));

        return $this->config[$configParam] ?? getenv($param, true) ?: getenv($param) ?: $_ENV[$param] ?? null;
    }
}
