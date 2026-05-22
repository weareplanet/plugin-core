<?php

namespace WeArePlanet\PluginCore\Examples\Common;

use WeArePlanet\PluginCore\Log\LoggerInterface;

/**
 * A simple logger implementation that outputs to stdout.
 */
class SimpleLogger implements LoggerInterface
{
    public function emergency(\Stringable|string $message, array $context = []): void
    {
        $this->log('EMERGENCY', $message, $context);
    }

    public function alert(\Stringable|string $message, array $context = []): void
    {
        $this->log('ALERT', $message, $context);
    }

    public function critical(\Stringable|string $message, array $context = []): void
    {
        $this->log('CRITICAL', $message, $context);
    }

    public function error(\Stringable|string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    public function warning(\Stringable|string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    public function notice(\Stringable|string $message, array $context = []): void
    {
        $this->log('NOTICE', $message, $context);
    }

    public function info(\Stringable|string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    public function debug(\Stringable|string $message, array $context = []): void
    {
        // Uncomment to see verbose debug output, or keep enabled if preferred for examples
        $this->log('DEBUG', $message, $context);
    }

    /**
     * Standard implementation of PSR-3 log method with mixed level type.
     */
    public function log(mixed $level, \Stringable|string $message, array $context = [],): void
    {
        $ctx = empty($context) ? '' : ' ' . json_encode($context);
        echo "[$level] $message$ctx\n";
    }

    public function __toString(): string
    {
        return self::class;
    }
}
