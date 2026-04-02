<?php

namespace WeArePlanet\PluginCore\Examples\Common;

use WeArePlanet\PluginCore\Log\LoggerInterface;
use Stringable;

class SimpleLogger implements \WeArePlanet\PluginCore\Log\LoggerInterface
{
    public function emergency(Stringable|string $message, array $context = []): void
    {
        $this->log('EMERGENCY', $message, $context);
    }

    public function alert(Stringable|string $message, array $context = []): void
    {
        $this->log('ALERT', $message, $context);
    }

    public function critical(Stringable|string $message, array $context = []): void
    {
        $this->log('CRITICAL', $message, $context);
    }

    public function error(Stringable|string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    public function warning(Stringable|string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    public function notice(Stringable|string $message, array $context = []): void
    {
        $this->log('NOTICE', $message, $context);
    }

    public function info(Stringable|string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    public function debug(Stringable|string $message, array $context = []): void
    {
        // Uncomment to enable verbose debug logging
        // $this->log('DEBUG', $message, $context);
    }

    public function log($level, Stringable|string $message, array $context = []): void
    {
        echo "[$level] $message\n";
    }

    public function __toString(): string
    {
        return self::class;
    }
}
