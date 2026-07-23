<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Log;

/**
 * Wires the Hybrid Logging Pattern into a class.
 *
 * Call {@see initializeLogger()} from the constructor with the injected raw
 * logger. When the class carries a {@see LogContext} attribute, the logger is
 * wrapped in a {@see DomainAwareLogger}; otherwise the raw logger is used
 * unchanged.
 *
 * @property LoggerInterface $logger
 */
trait DomainLoggerTrait
{
    protected LoggerInterface $logger;

    protected function initializeLogger(LoggerInterface $rawLogger): void
    {
        $attributes = (new \ReflectionClass($this))->getAttributes(LogContext::class);

        if ($attributes === []) {
            $this->logger = $rawLogger;
            return;
        }

        $this->logger = new DomainAwareLogger($rawLogger, $attributes[0]->newInstance());
    }
}
