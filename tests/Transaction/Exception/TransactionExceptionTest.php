<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Transaction\Exception;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Transaction\Exception\TransactionException;

class TransactionExceptionTest extends TestCase
{
    public function testAConflictIsAlwaysRetryable(): void
    {
        $exception = (new TransactionException('Update conflicted.'))->withConflict();

        $this->assertTrue($exception->isConflict());
        $this->assertTrue($exception->isRetryable());
    }

    public function testARetryableFailureIsNotAConflict(): void
    {
        $exception = (new TransactionException('Connection lost.'))->withRetryable(true);

        $this->assertTrue($exception->isRetryable());
        $this->assertFalse($exception->isConflict());
    }
    public function testIsNeitherAConflictNorRetryableByDefault(): void
    {
        $exception = new TransactionException('Update failed.');

        $this->assertFalse($exception->isConflict());
        $this->assertFalse($exception->isRetryable());
    }
}
