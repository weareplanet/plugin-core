<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Webhook;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Webhook\Enum\WebhookListener;
use WeArePlanet\PluginCore\Webhook\StateValidator;

class StateValidatorTest extends TestCase
{
    private StateValidator $validator;

    /**
     * @return array<string, array{0: WebhookListener, 1: ?string, 2: string, 3: ?array<string>}>
     */
    public static function deliveryIndicationTransitionProvider(): array
    {
        return [
            'delivery: valid initial state is PENDING' => [WebhookListener::DELIVERY_INDICATION, null, 'PENDING', ['PENDING']],
            'delivery: valid: PENDING to SUITABLE' => [WebhookListener::DELIVERY_INDICATION, 'PENDING', 'SUITABLE', ['SUITABLE']],
            'delivery: invalid: SUITABLE to PENDING' => [WebhookListener::DELIVERY_INDICATION, 'SUITABLE', 'PENDING', null],
            'delivery: final to same final is valid (NOT_SUITABLE)' => [WebhookListener::DELIVERY_INDICATION, 'NOT_SUITABLE', 'NOT_SUITABLE', []],
        ];
    }

    /**
     * @return array<string, array{0: WebhookListener, 1: ?string, 2: string, 3: ?array<string>}>
     */
    public static function manualTaskTransitionProvider(): array
    {
        return [
            'manual_task: valid initial state is OPEN' => [WebhookListener::MANUAL_TASK, null, 'OPEN', ['OPEN']],
            'manual_task: valid: OPEN to DONE' => [WebhookListener::MANUAL_TASK, 'OPEN', 'DONE', ['DONE']],
            'manual_task: valid: OPEN to EXPIRED' => [WebhookListener::MANUAL_TASK, 'OPEN', 'EXPIRED', ['EXPIRED']],
            'manual_task: invalid: DONE to OPEN' => [WebhookListener::MANUAL_TASK, 'DONE', 'OPEN', null],
        ];
    }

    /**
     * @return array<string, array{0: WebhookListener, 1: ?string, 2: string, 3: ?array<string>}>
     */
    public static function refundTransitionProvider(): array
    {
        return [
            'refund: valid initial state is CREATE' => [WebhookListener::REFUND, null, 'CREATE', ['CREATE']],
            'refund: valid: CREATE to SCHEDULED' => [WebhookListener::REFUND, 'CREATE', 'SCHEDULED', ['SCHEDULED']],
            'refund: valid: SCHEDULED to PENDING' => [WebhookListener::REFUND, 'SCHEDULED', 'PENDING', ['PENDING']],
            'refund: valid: PENDING to MANUAL_CHECK' => [WebhookListener::REFUND, 'PENDING', 'MANUAL_CHECK', ['MANUAL_CHECK']],
            'refund: valid: MANUAL_CHECK to SUCCESSFUL' => [WebhookListener::REFUND, 'MANUAL_CHECK', 'SUCCESSFUL', ['SUCCESSFUL']],
            'refund: invalid: SUCCESSFUL to PENDING' => [WebhookListener::REFUND, 'SUCCESSFUL', 'PENDING', null],
        ];
    }

    protected function setUp(): void
    {
        $this->validator = new StateValidator();
    }

    /**
     * @param array<string>|null $expectedPath
     */
    #[DataProvider('transactionTransitionProvider')]
    #[DataProvider('transactionVoidTransitionProvider')]
    #[DataProvider('transactionCompletionTransitionProvider')]
    #[DataProvider('transactionInvoiceTransitionProvider')]
    #[DataProvider('refundTransitionProvider')]
    #[DataProvider('tokenVersionTransitionProvider')]
    #[DataProvider('manualTaskTransitionProvider')]
    #[DataProvider('deliveryIndicationTransitionProvider')]
    public function testGetTransitionPath(
        WebhookListener $listener,
        ?string $localState,
        string $remoteState,
        ?array $expectedPath,
    ): void {
        $path = $this->validator->getTransitionPath(
            $listener,
            $localState,
            $remoteState,
        );

        $this->assertSame(
            $expectedPath,
            $path,
            sprintf(
                'Failed asserting that transition path from %s to %s is %s for listener %s.',
                $localState ?? 'null',
                $remoteState,
                json_encode($expectedPath),
                $listener->getTechnicalName(),
            ),
        );
    }

    /**
     * @return array<string, array{0: WebhookListener, 1: ?string, 2: string, 3: ?array<string>}>
     */
    public static function tokenVersionTransitionProvider(): array
    {
        return [
            'token: valid initial state is UNINITIALIZED' => [WebhookListener::TOKEN_VERSION, null, 'UNINITIALIZED', ['UNINITIALIZED']],
            'token: valid: UNINITIALIZED to ACTIVE' => [WebhookListener::TOKEN_VERSION, 'UNINITIALIZED', 'ACTIVE', ['ACTIVE']],
            'token: valid: ACTIVE to OBSOLETE' => [WebhookListener::TOKEN_VERSION, 'ACTIVE', 'OBSOLETE', ['OBSOLETE']],
            'token: invalid: ACTIVE to UNINITIALIZED' => [WebhookListener::TOKEN_VERSION, 'ACTIVE', 'UNINITIALIZED', null],
            'token: final to same final is valid (OBSOLETE)' => [WebhookListener::TOKEN_VERSION, 'OBSOLETE', 'OBSOLETE', []],
        ];
    }

    /**
     * @return array<string, array{0: WebhookListener, 1: ?string, 2: string, 3: ?array<string>}>
     */
    public static function transactionCompletionTransitionProvider(): array
    {
        return [
            'completion: valid initial state is CREATE' => [WebhookListener::TRANSACTION_COMPLETION, null, 'CREATE', ['CREATE']],
            'completion: valid: CREATE to SCHEDULED' => [WebhookListener::TRANSACTION_COMPLETION, 'CREATE', 'SCHEDULED', ['SCHEDULED']],
            'completion: valid: SCHEDULED to PENDING' => [WebhookListener::TRANSACTION_COMPLETION, 'SCHEDULED', 'PENDING', ['PENDING']],
            'completion: invalid: PENDING to SCHEDULED' => [WebhookListener::TRANSACTION_COMPLETION, 'PENDING', 'SCHEDULED', null],
            'completion: final to same final is valid (FAILED)' => [WebhookListener::TRANSACTION_COMPLETION, 'FAILED', 'FAILED', []],
        ];
    }

    /**
     * @return array<string, array{0: WebhookListener, 1: ?string, 2: string, 3: ?array<string>}>
     */
    public static function transactionInvoiceTransitionProvider(): array
    {
        return [
            'invoice: valid initial state is CREATE' => [WebhookListener::TRANSACTION_INVOICE, null, 'CREATE', ['CREATE']],
            'invoice: valid: CREATE to OPEN' => [WebhookListener::TRANSACTION_INVOICE, 'CREATE', 'OPEN', ['OPEN']],
            'invoice: valid: OPEN to OVERDUE' => [WebhookListener::TRANSACTION_INVOICE, 'OPEN', 'OVERDUE', ['OVERDUE']],
            'invoice: valid: OVERDUE to PAID' => [WebhookListener::TRANSACTION_INVOICE, 'OVERDUE', 'PAID', ['PAID']],
            'invoice: invalid: PAID to OPEN' => [WebhookListener::TRANSACTION_INVOICE, 'PAID', 'OPEN', null],
        ];
    }

    /**
     * @return array<string, array{0: WebhookListener, 1: ?string, 2: string, 3: ?array<string>}>
     */
    public static function transactionTransitionProvider(): array
    {
        return [
            'valid initial state is CREATE' => [WebhookListener::TRANSACTION, null, 'CREATE', ['CREATE']],
            'invalid initial state is PENDING' => [WebhookListener::TRANSACTION, null, 'PENDING', null],
            'valid: CREATE to PENDING' => [WebhookListener::TRANSACTION, 'CREATE', 'PENDING', ['PENDING']],
            'valid: AUTHORIZED to COMPLETED' => [WebhookListener::TRANSACTION, 'AUTHORIZED', 'COMPLETED', ['COMPLETED']],
            'valid: COMPLETED to FULFILL' => [WebhookListener::TRANSACTION, 'COMPLETED', 'FULFILL', ['FULFILL']],
            'valid skip-ahead: CREATE to PROCESSING' => [WebhookListener::TRANSACTION, 'CREATE', 'PROCESSING', ['PENDING', 'CONFIRMED', 'PROCESSING']],
            'valid: PENDING to FAILED' => [WebhookListener::TRANSACTION, 'PENDING', 'FAILED', ['FAILED']],
            'invalid: AUTHORIZED to PENDING' => [WebhookListener::TRANSACTION, 'AUTHORIZED', 'PENDING', null],
            'final to same final is valid (FAILED)' => [WebhookListener::TRANSACTION, 'FAILED', 'FAILED', []],
            'final to same final is valid (VOIDED)' => [WebhookListener::TRANSACTION, 'VOIDED', 'VOIDED', []],
            'final to non-final is invalid (FAILED)' => [WebhookListener::TRANSACTION, 'FAILED', 'PENDING', null],
            'final to non-final is invalid (FULFILL)' => [WebhookListener::TRANSACTION, 'FULFILL', 'COMPLETED', null],
        ];
    }

    /**
     * @return array<string, array{0: WebhookListener, 1: ?string, 2: string, 3: ?array<string>}>
     */
    public static function transactionVoidTransitionProvider(): array
    {
        return [
            'void: valid initial state is CREATE' => [WebhookListener::TRANSACTION_VOID, null, 'CREATE', ['CREATE']],
            'void: invalid initial state is PENDING' => [WebhookListener::TRANSACTION_VOID, null, 'PENDING', null],
            'void: valid: CREATE to PENDING' => [WebhookListener::TRANSACTION_VOID, 'CREATE', 'PENDING', ['PENDING']],
            'void: valid: PENDING to SUCCESSFUL' => [WebhookListener::TRANSACTION_VOID, 'PENDING', 'SUCCESSFUL', ['SUCCESSFUL']],
            'void: invalid: PENDING to CREATE' => [WebhookListener::TRANSACTION_VOID, 'PENDING', 'CREATE', null],
            'void: final to same final is valid (SUCCESSFUL)' => [WebhookListener::TRANSACTION_VOID, 'SUCCESSFUL', 'SUCCESSFUL', []],
            'void: final to non-final is invalid (FAILED)' => [WebhookListener::TRANSACTION_VOID, 'FAILED', 'PENDING', null],
        ];
    }
}
