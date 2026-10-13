<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\SharedKernel;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\SharedKernel\AbstractDomainException;
use WeArePlanet\PluginCore\SharedKernel\RetryableOperation;

class RetryableOperationTest extends TestCase
{
    /**
     * @var list<int> Delays the operation was asked to pause for.
     */
    private array $pauses = [];

    protected function setUp(): void
    {
        $this->pauses = [];
    }

    private function operation(int $attempts = 5): RetryableOperation
    {
        return new RetryableOperation($attempts, 400, function (int $delayMs): void {
            $this->pauses[] = $delayMs;
        });
    }

    private function failure(bool $retryable): AbstractDomainException
    {
        $failure = new class ('The request failed.') extends AbstractDomainException {
        };

        return $failure->withRetryable($retryable);
    }

    public function testReturnsTheResultWithoutPausingWhenTheFirstAttemptSucceeds(): void
    {
        $result = $this->operation()->run(static fn (int $attempt): string => "done on $attempt");

        $this->assertSame('done on 1', $result);
        $this->assertSame([], $this->pauses);
    }

    public function testRetriesARetryableFailureAndReturnsTheLaterResult(): void
    {
        $calls = [];

        $result = $this->operation()->run(function (int $attempt) use (&$calls): string {
            $calls[] = $attempt;
            if ($attempt < 3) {
                throw $this->failure(true);
            }

            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame([1, 2, 3], $calls);
        $this->assertCount(2, $this->pauses);
    }

    public function testRethrowsANonRetryableFailureAtOnceWithoutPausing(): void
    {
        $failure = $this->failure(false);
        $calls = 0;
        $caught = null;

        try {
            $this->operation()->run(function () use (&$calls, $failure): string {
                $calls++;
                throw $failure;
            });
        } catch (AbstractDomainException $e) {
            $caught = $e;
        }

        $this->assertSame($failure, $caught);
        $this->assertSame(1, $calls);
        $this->assertSame([], $this->pauses);
    }

    public function testRethrowsTheLastRetryableFailureOnceTheAttemptsRunOut(): void
    {
        $failures = [];
        $calls = 0;
        $caught = null;

        try {
            $this->operation(3)->run(function () use (&$calls, &$failures): string {
                $calls++;
                $failures[] = $failure = $this->failure(true);
                throw $failure;
            });
        } catch (AbstractDomainException $e) {
            $caught = $e;
        }

        $this->assertSame($failures[2], $caught);
        $this->assertSame(3, $calls);
        $this->assertCount(2, $this->pauses, 'No pause after the last attempt.');
    }

    public function testDoesNotRetryFailuresThatAreNotDomainExceptionsByDefault(): void
    {
        $calls = 0;

        $this->expectException(\RuntimeException::class);

        try {
            $this->operation()->run(function () use (&$calls): string {
                $calls++;
                throw new \RuntimeException('Unexpected.');
            });
        } finally {
            $this->assertSame(1, $calls);
            $this->assertSame([], $this->pauses);
        }
    }

    public function testACustomPredicateDecidesWhichFailuresAreRetried(): void
    {
        $calls = 0;

        // The failure is not marked retryable, but the caller says it is worth another attempt.
        $result = $this->operation()->run(
            function () use (&$calls): string {
                if (++$calls < 2) {
                    throw $this->failure(false);
                }

                return 'ok';
            },
            static fn (\Throwable $e): bool => $e instanceof AbstractDomainException,
        );

        $this->assertSame('ok', $result);
        $this->assertSame(2, $calls);
    }

    public function testACustomPredicateCanRefuseAFailureThatIsMarkedRetryable(): void
    {
        $calls = 0;

        $this->expectException(AbstractDomainException::class);

        try {
            $this->operation()->run(
                function () use (&$calls): string {
                    $calls++;
                    throw $this->failure(true);
                },
                static fn (\Throwable $e): bool => false,
            );
        } finally {
            $this->assertSame(1, $calls);
        }
    }

    public function testCallsOnRetryBeforeEachPauseWithTheFailureAndTheFailedAttempt(): void
    {
        $seen = [];

        $this->operation(3)->run(
            function (int $attempt): string {
                if ($attempt < 3) {
                    throw $this->failure(true);
                }

                return 'ok';
            },
            null,
            function (\Throwable $e, int $attempt) use (&$seen): void {
                $seen[] = [$e->getMessage(), $attempt, count($this->pauses)];
            },
        );

        // The callback runs before the pause of the same round, so the pause count lags by one.
        $this->assertSame([['The request failed.', 1, 0], ['The request failed.', 2, 1]], $seen);
    }

    public function testAlwaysMakesAtLeastOneAttempt(): void
    {
        $calls = 0;

        $result = $this->operation(0)->run(function () use (&$calls): string {
            $calls++;

            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(1, $calls);
    }

    public function testPausesStayWithinHalfOfTheBaseDelayInEitherDirection(): void
    {
        $this->expectException(AbstractDomainException::class);

        try {
            $this->operation(201)->run(function (): string {
                throw $this->failure(true);
            });
        } finally {
            $this->assertCount(200, $this->pauses);
            $this->assertGreaterThanOrEqual(200, min($this->pauses));
            $this->assertLessThanOrEqual(600, max($this->pauses));
            $this->assertGreaterThan(1, count(array_unique($this->pauses)), 'The delay should vary between attempts.');
        }
    }
}
