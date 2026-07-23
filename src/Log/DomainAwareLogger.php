<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Log;

/**
 * Logger decorator implementing the Hybrid Logging Pattern.
 *
 * Prepends `[Domain] ` to every message (for humans tailing the unified log)
 * and merges `domain`, `subdomain` and `source` into the context array (for
 * log aggregators).
 */
final class DomainAwareLogger implements LoggerInterface
{
    public function __construct(
        private readonly LoggerInterface $delegate,
        private readonly LogContext $logContext,
    ) {
    }

    /** @param array<string, mixed> $context */
    public function alert(string|\Stringable $message, array $context = []): void
    {
        $this->delegate->alert($this->prefix($message), $this->merge($context));
    }

    /** @param array<string, mixed> $context */
    public function critical(string|\Stringable $message, array $context = []): void
    {
        $this->delegate->critical($this->prefix($message), $this->merge($context));
    }

    /** @param array<string, mixed> $context */
    public function debug(string|\Stringable $message, array $context = []): void
    {
        $this->delegate->debug($this->prefix($message), $this->merge($context));
    }

    /** @param array<string, mixed> $context */
    public function emergency(string|\Stringable $message, array $context = []): void
    {
        $this->delegate->emergency($this->prefix($message), $this->merge($context));
    }

    /** @param array<string, mixed> $context */
    public function error(string|\Stringable $message, array $context = []): void
    {
        $this->delegate->error($this->prefix($message), $this->merge($context));
    }

    /** @param array<string, mixed> $context */
    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->delegate->info($this->prefix($message), $this->merge($context));
    }

    /**
     * @param mixed $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->delegate->log($level, $this->prefix($message), $this->merge($context));
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function merge(array $context): array
    {
        $defaults = [
            'domain' => $this->logContext->domain,
            'source' => $this->logContext->source,
        ];
        if ($this->logContext->subdomain !== null) {
            $defaults['subdomain'] = $this->logContext->subdomain;
        }

        return $context + $defaults;
    }

    /** @param array<string, mixed> $context */
    public function notice(string|\Stringable $message, array $context = []): void
    {
        $this->delegate->notice($this->prefix($message), $this->merge($context));
    }

    private function prefix(string|\Stringable $message): string
    {
        return '[' . ucfirst($this->logContext->domain) . '] ' . $message;
    }

    /** @param array<string, mixed> $context */
    public function warning(string|\Stringable $message, array $context = []): void
    {
        $this->delegate->warning($this->prefix($message), $this->merge($context));
    }
}
