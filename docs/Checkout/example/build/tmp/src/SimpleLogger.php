<?php

namespace MyPlugin\ExampleCheckoutImplementation;

use WeArePlanet\PluginCore\Log\LoggerInterface;

class SimpleLogger implements LoggerInterface
{
    public function emergency(\Stringable|string $message, array $context = []): void
    {
        echo "[EMERGENCY] $message\n";
    }

    public function alert(\Stringable|string $message, array $context = []): void
    {
        echo "[ALERT] $message\n";
    }

    public function critical(\Stringable|string $message, array $context = []): void
    {
        echo "[CRITICAL] $message\n";
    }

    public function error(\Stringable|string $message, array $context = []): void
    {
        echo "[ERROR] $message\n";
    }

    public function warning(\Stringable|string $message, array $context = []): void
    {
        echo "[WARNING] $message\n";
    }

    public function notice(\Stringable|string $message, array $context = []): void
    {
        echo "[NOTICE] $message\n";
    }

    public function info(\Stringable|string $message, array $context = []): void
    {
        echo "[INFO] $message\n";
    }

    public function debug(\Stringable|string $message, array $context = []): void
    {
        // Uncomment to see verbose debug output
        // echo "[DEBUG] $message\n";
    }

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        echo "[$level] $message\n";
    }

    public function __toString(): string
    {
        return self::class;
    }
}
