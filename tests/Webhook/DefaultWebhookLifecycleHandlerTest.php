<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Webhook;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Webhook\DefaultWebhookLifecycleHandler;
use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener;
use WeArePlanet\PluginCore\Webhook\WebhookContext;

/**
 * A test stub to expose protected methods and track calls.
 */
class TestableLifecycleHandler extends DefaultWebhookLifecycleHandler
{
    /** @var list<string> */
    public array $acquiredLocks = [];
    /** @var list<string> */
    public array $releasedLocks = [];

    protected function doAcquireLock(string $resourceId): void
    {
        $this->acquiredLocks[] = $resourceId;
    }

    protected function doReleaseLock(string $resourceId): void
    {
        $this->releasedLocks[] = $resourceId;
    }

    // Implement mandatory method
    public function getLastProcessedState(WebhookListener $listener, int $entityId): string
    {
        return 'CREATE';
    }

    // Return dummy resources to test the loop
    public function getLockableResources(WebhookListener $listener, WebhookContext $context): array
    {
        return ['resource_1', 'resource_2'];
    }

    // Helper to test the protected helper method
    public function publicCallFindDefaultInitialState(WebhookListener $listener): string
    {
        return $this->findDefaultInitialState($listener);
    }
}


class DefaultWebhookLifecycleHandlerTest extends TestCase
{
    private WebhookContext $context;
    private TestableLifecycleHandler $handler;

    /**
     * This provides a test case for every listener type and its
     * expected initial state.
     */
    /**
     * @return array<string, array{0: WebhookListener, 1: string}>
     */
    public static function initialStateProvider(): array
    {
        return [
            'Transaction' => [WebhookListener::TRANSACTION, 'CREATE'],
            'Transaction Void' => [WebhookListener::TRANSACTION_VOID, 'CREATE'],
            'Transaction Completion' => [WebhookListener::TRANSACTION_COMPLETION, 'CREATE'],
            'Transaction Invoice' => [WebhookListener::TRANSACTION_INVOICE, 'CREATE'],
            'Refund' => [WebhookListener::REFUND, 'CREATE'],
            'Token Version' => [WebhookListener::TOKEN_VERSION, 'UNINITIALIZED'],
            'Manual Task' => [WebhookListener::MANUAL_TASK, 'OPEN'],
            'Delivery Indication' => [WebhookListener::DELIVERY_INDICATION, 'PENDING'],
            'Token (no state enum)' => [WebhookListener::TOKEN, 'CREATE'],
            'Payment Method (no state enum)' => [WebhookListener::PAYMENT_METHOD_CONFIGURATION, 'CREATE'],
        ];
    }

    protected function setUp(): void
    {
        $this->handler = new TestableLifecycleHandler();
        $this->context = new WebhookContext('REMOTE', 'LOCAL', 123, 1);
    }

    #[DataProvider('initialStateProvider')]
    public function testFindDefaultInitialStateReturnsCorrectState(WebhookListener $listener, string $expectedInitialState): void
    {
        $result = $this->handler->publicCallFindDefaultInitialState($listener);

        $this->assertSame($expectedInitialState, $result, "Failed for listener: {$listener->getTechnicalName()}");
    }

    public function testOnFailureReleasesLocksInReverseOrder(): void
    {
        $listener = WebhookListener::TRANSACTION;

        $this->handler->onFailure($listener, $this->context, new \Exception());

        $this->assertEquals(['resource_2', 'resource_1'], $this->handler->releasedLocks);
    }

    public function testPostProcessReleasesLocksInReverseOrder(): void
    {
        $listener = WebhookListener::TRANSACTION;

        $this->handler->postProcess($listener, $this->context, null);

        // Should release in reverse order (LIFO)
        $this->assertEquals(['resource_2', 'resource_1'], $this->handler->releasedLocks);
    }

    public function testPreProcessAcquiresLocks(): void
    {
        $listener = WebhookListener::TRANSACTION;

        $this->handler->preProcess($listener, $this->context);

        $this->assertEquals(['resource_1', 'resource_2'], $this->handler->acquiredLocks);
    }
}
