<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\SharedKernel;

/**
 * Runs an operation again while it keeps failing with a failure worth retrying.
 *
 * By default a failure is retried when it is a domain exception that reports
 * {@see AbstractDomainException::isRetryable()}, such as a lost connection or a concurrent
 * modification of the same entity. Any other failure is rethrown at once, since repeating the
 * request would only repeat the rejection. When the attempts run out, the last failure is
 * rethrown so the caller decides what to do next.
 *
 * Pauses between attempts vary by up to half of the base delay in either direction, so requests
 * that failed together do not wake up and retry at the same instant.
 */
final class RetryableOperation
{
    /**
     * @var \Closure(int): void Receives the delay in milliseconds.
     */
    private readonly \Closure $pause;

    /**
     * @param int $attempts Total number of tries, the first one included. At least one.
     * @param int $baseDelayMs Base pause between tries, in milliseconds.
     * @param (callable(int): void)|null $pause Replaces the real sleep; receives the delay in milliseconds.
     */
    public function __construct(
        private readonly int $attempts = 5,
        private readonly int $baseDelayMs = 400,
        ?callable $pause = null,
    ) {
        $this->pause = $pause !== null
            ? \Closure::fromCallable($pause)
            : static function (int $delayMs): void {
                usleep($delayMs * 1000);
            };
    }

    /**
     * The pause before the next attempt, in milliseconds.
     */
    public function delayMs(): int
    {
        $spread = intdiv($this->baseDelayMs, 2);

        return random_int($this->baseDelayMs - $spread, $this->baseDelayMs + $spread);
    }

    /**
     * Runs the operation, retrying it while it fails with a failure worth retrying.
     *
     * The operation should read whatever state it depends on again each time it runs, so a retry
     * works from fresh data.
     *
     * @template T
     * @param callable(int): T $operation Receives the attempt number, starting at 1.
     * @param (callable(\Throwable): bool)|null $shouldRetry Decides whether a failure is worth another
     *        attempt. Defaults to retryable domain exceptions.
     * @param (callable(\Throwable, int): void)|null $onRetry Called before each pause with the failure
     *        and the number of the attempt that failed.
     * @return T The operation's result.
     * @throws \Throwable A failure that is not worth retrying, or the last one once the attempts run out.
     */
    public function run(callable $operation, ?callable $shouldRetry = null, ?callable $onRetry = null): mixed
    {
        $maxAttempts = max(1, $this->attempts);

        for ($attempt = 1; ; $attempt++) {
            try {
                return $operation($attempt);
            } catch (\Throwable $e) {
                $retry = $shouldRetry !== null
                    ? $shouldRetry($e)
                    : ($e instanceof AbstractDomainException && $e->isRetryable());

                if (!$retry || $attempt >= $maxAttempts) {
                    throw $e;
                }

                if ($onRetry !== null) {
                    $onRetry($e, $attempt);
                }

                ($this->pause)($this->delayMs());
            }
        }
    }
}
