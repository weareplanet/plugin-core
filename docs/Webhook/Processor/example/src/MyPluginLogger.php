<?php

declare(strict_types=1);

namespace MyPlugin\ExampleWebhookImplementation;

use WeArePlanet\PluginCore\Log\LoggerInterface;

/**
 * A self-contained dummy Logger that implements our internal LoggerInterface.
 */
class MyPluginLogger implements LoggerInterface
{
    private const LEVELS = [
        'DEBUG' => 0,
        'INFO'  => 1,
        'NOTICE' => 2,
        'WARNING' => 3,
        'ERROR' => 4,
        'CRITICAL' => 5,
    ];

    private int $minLevel;

    public function __construct(string $minLevelStr = 'INFO')
    {
        $this->minLevel = self::LEVELS[strtoupper($minLevelStr)] ?? 1;
    }

    /**
     * Standard implementation of PSR-3 log method with mixed level type.
     */
    public function log(mixed $level, \Stringable|string $message, array $context = [],): void
    {
        $levelName = strtoupper((string) $level);
        $messageLevel = self::LEVELS[$levelName] ?? 1;

        if ($messageLevel >= $this->minLevel) {
            // Write to stderr so it doesn't interfere with standard output if piped
            file_put_contents('php://stderr', "[LOG:{$levelName}] {$message}\n");
        }
    }

    public function emergency(\Stringable|string $message, array $context = []): void { $this->log('emergency', $message, $context); }
    public function alert(\Stringable|string $message, array $context = []): void { $this->log('alert', $message, $context); }
    public function critical(\Stringable|string $message, array $context = []): void { $this->log('critical', $message, $context); }
    public function error(\Stringable|string $message, array $context = []): void { $this->log('error', $message, $context); }
    public function warning(\Stringable|string $message, array $context = []): void { $this->log('warning', $message, $context); }
    public function notice(\Stringable|string $message, array $context = []): void { $this->log('notice', $message, $context); }
    public function info(\Stringable|string $message, array $context = []): void { $this->log('info', $message, $context); }
    public function debug(\Stringable|string $message, array $context = []): void { $this->log('debug', $message, $context); }
    public function __toString(): string { return ''; }
}
