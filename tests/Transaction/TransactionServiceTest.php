<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Transaction;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Address\Address;
use WeArePlanet\PluginCore\Customer\PersonalDetails;
use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\LineItem\LineItemCollection;
use WeArePlanet\PluginCore\LineItem\LineItemConsistencyService;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethod;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethodCollection;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethodSorting;
use WeArePlanet\PluginCore\PaymentMethod\State as PaymentMethodState;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\PluginCore\SharedKernel\Url;
use WeArePlanet\PluginCore\Transaction\Exception\TransactionException;
use WeArePlanet\PluginCore\Transaction\Exception\TransactionNotConfirmableException;
use WeArePlanet\PluginCore\Transaction\PaymentUrl;
use WeArePlanet\PluginCore\Transaction\State;
use WeArePlanet\PluginCore\Transaction\Transaction;
use WeArePlanet\PluginCore\Transaction\TransactionCollection;
use WeArePlanet\PluginCore\Transaction\TransactionContext;
use WeArePlanet\PluginCore\Transaction\TransactionGatewayInterface;
use WeArePlanet\PluginCore\Transaction\TransactionPersistenceInterface;
use WeArePlanet\PluginCore\Transaction\TransactionSearchCriteria;
use WeArePlanet\PluginCore\Transaction\TransactionService;

class TransactionServiceTest extends TestCase
{
    private MockObject|LineItemConsistencyService $consistencyService;
    private MockObject|TransactionGatewayInterface $gateway;
    private MockObject|LoggerInterface $logger;
    private MockObject|TransactionPersistenceInterface $persistence;
    private TransactionService $service;
    private MockObject|Settings $settings;

    private function pendingTransaction(int $version): Transaction
    {
        $transaction = new Transaction();
        $transaction->id = 123;
        $transaction->version = $version;
        $transaction->state = State::PENDING;

        return $transaction;
    }

    private function retryableFailure(string $message = 'Connection lost.'): TransactionException
    {
        return (new TransactionException($message, new LocalizedString($message)))->withRetryable(true);
    }

    /**
     * @param list<int> $pauses Collects the delays the service asks to pause for.
     */
    private function serviceRecordingPauses(array &$pauses): TransactionService
    {
        return new class ($this->gateway, $this->consistencyService, $this->logger, $pauses) extends TransactionService {
            /**
             * @param list<int> $pauses
             */
            public function __construct(
                TransactionGatewayInterface $gateway,
                LineItemConsistencyService $consistencyService,
                LoggerInterface $logger,
                private array &$pauses,
            ) {
                parent::__construct($gateway, $consistencyService, $logger);
            }

            protected function pause(int $delayMs): void
            {
                $this->pauses[] = $delayMs;
            }
        };
    }

    private function serviceWithoutRetryDelay(): TransactionService
    {
        return new class ($this->gateway, $this->consistencyService, $this->logger) extends TransactionService {
            protected function pause(int $delayMs): void
            {
            }
        };
    }

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(TransactionGatewayInterface::class);
        $this->consistencyService = $this->createMock(LineItemConsistencyService::class);
        $this->settings = $this->createMock(Settings::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->persistence = $this->createMock(TransactionPersistenceInterface::class);

        $this->consistencyService->method('ensureConsistency')
            ->willReturnCallback(fn (array $items): LineItemCollection => new LineItemCollection(...$items));

        $this->service = new TransactionService(
            $this->gateway,
            $this->consistencyService,
            $this->logger,
        );
    }

    public function testConfirmationRetriesPauseForARandomTimeAroundTheBaseDelay(): void
    {
        $this->gateway->method('get')->willReturn($this->pendingTransaction(5));
        $this->gateway->expects($this->exactly(3))->method('confirm')->willThrowException($this->retryableFailure());

        $pauses = [];
        try {
            $this->serviceRecordingPauses($pauses)->confirmTransaction(1, 123);
        } catch (TransactionException) {
        }

        $this->assertCount(2, $pauses, 'One pause between each of the three attempts.');
        foreach ($pauses as $pause) {
            $this->assertGreaterThanOrEqual(200, $pause);
            $this->assertLessThanOrEqual(600, $pause);
        }
    }

    public function testConfirmTransactionConfirmsThenReadsAgain(): void
    {
        $final = $this->transactionInState(State::CONFIRMED, 7);
        $calls = [];

        $this->gateway->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(function () use (&$calls, $final): Transaction {
                $calls[] = 'get';

                return count($calls) === 1 ? $this->pendingTransaction(5) : $final;
            });
        $this->gateway->expects($this->once())
            ->method('confirm')
            ->with(1, 123)
            ->willReturnCallback(function () use (&$calls): Transaction {
                $calls[] = 'confirm';

                return $this->transactionInState(State::CONFIRMED, 6);
            });

        $result = $this->serviceWithoutRetryDelay()->confirmTransaction(1, 123);

        $this->assertSame(['get', 'confirm', 'get'], $calls);
        $this->assertSame($final, $result);
    }

    public function testConfirmTransactionGivesUpAfterThreeRetryableFailuresAndRethrowsTheLastOne(): void
    {
        $failures = [];

        $this->gateway->expects($this->exactly(3))->method('get')->willReturn($this->pendingTransaction(5));
        $this->gateway->expects($this->exactly(3))
            ->method('confirm')
            ->willReturnCallback(function () use (&$failures): Transaction {
                $failures[] = $failure = $this->retryableFailure('Attempt ' . (count($failures) + 1));
                throw $failure;
            });

        try {
            $this->serviceWithoutRetryDelay()->confirmTransaction(1, 123);
            $this->fail('Expected the last failure to be rethrown.');
        } catch (TransactionException $e) {
            $this->assertSame($failures[2], $e);
        }
    }

    public function testConfirmTransactionRecoversWhenTheConfirmationWentThroughButItsResponseWasLost(): void
    {
        $confirmed = $this->transactionInState(State::CONFIRMED, 7);

        $this->gateway->method('get')->willReturnOnConsecutiveCalls($this->pendingTransaction(5), $confirmed);
        $this->gateway->expects($this->once())->method('confirm')->willThrowException($this->retryableFailure());

        $this->assertSame($confirmed, $this->serviceWithoutRetryDelay()->confirmTransaction(1, 123));
    }

    public function testConfirmTransactionRejectsATransactionThatIsNeitherPendingNorConfirmed(): void
    {
        $this->gateway->expects($this->once())->method('get')->willReturn($this->transactionInState(State::FAILED));
        $this->gateway->expects($this->never())->method('confirm');

        try {
            $this->serviceWithoutRetryDelay()->confirmTransaction(1, 123);
            $this->fail('Expected a TransactionNotConfirmableException.');
        } catch (TransactionNotConfirmableException $e) {
            $this->assertSame(State::FAILED, $e->state);
            $this->assertFalse($e->isRetryable());
        }
    }

    public function testConfirmTransactionRejectsATransactionThatLeftPendingDuringARetry(): void
    {
        $this->gateway->method('get')->willReturnOnConsecutiveCalls(
            $this->pendingTransaction(5),
            $this->transactionInState(State::FAILED, 6),
        );
        $this->gateway->expects($this->once())->method('confirm')->willThrowException($this->retryableFailure());

        $this->expectException(TransactionNotConfirmableException::class);

        $this->serviceWithoutRetryDelay()->confirmTransaction(1, 123);
    }

    public function testConfirmTransactionRethrowsANonRetryableFailureWithoutFurtherAttempts(): void
    {
        $failure = new TransactionException('Rejected.', new LocalizedString('Rejected.'));

        $this->gateway->expects($this->once())->method('get')->willReturn($this->pendingTransaction(5));
        $this->gateway->expects($this->once())->method('confirm')->willThrowException($failure);

        try {
            $this->serviceWithoutRetryDelay()->confirmTransaction(1, 123);
            $this->fail('Expected the failure to be rethrown.');
        } catch (TransactionException $e) {
            $this->assertSame($failure, $e);
        }
    }

    public function testConfirmTransactionRetriesAReadThatFailsWithARetryableError(): void
    {
        $confirmed = $this->transactionInState(State::CONFIRMED, 6);

        $this->gateway->method('get')->willReturnCallback(function () use ($confirmed): Transaction {
            static $calls = 0;
            if (++$calls === 1) {
                throw $this->retryableFailure();
            }

            return $confirmed;
        });
        $this->gateway->expects($this->never())->method('confirm');

        $this->assertSame($confirmed, $this->serviceWithoutRetryDelay()->confirmTransaction(1, 123));
    }

    public function testConfirmTransactionRetriesARetryableFailureAfterReadingAgain(): void
    {
        $final = $this->transactionInState(State::CONFIRMED, 7);

        $this->gateway->expects($this->exactly(3))
            ->method('get')
            ->willReturnOnConsecutiveCalls($this->pendingTransaction(5), $this->pendingTransaction(5), $final);
        $this->gateway->expects($this->exactly(2))
            ->method('confirm')
            ->willReturnCallback(function (): Transaction {
                static $calls = 0;
                if (++$calls === 1) {
                    throw $this->retryableFailure('[409] The request conflicts with another request.');
                }

                return $this->transactionInState(State::CONFIRMED, 6);
            });

        $this->assertSame($final, $this->serviceWithoutRetryDelay()->confirmTransaction(1, 123));
    }

    public function testConfirmTransactionReturnsAnAlreadyConfirmedTransactionWithoutConfirmingAgain(): void
    {
        $confirmed = $this->transactionInState(State::CONFIRMED);

        $this->gateway->expects($this->once())->method('get')->with(1, 123)->willReturn($confirmed);
        $this->gateway->expects($this->never())->method('confirm');

        $this->assertSame($confirmed, $this->serviceWithoutRetryDelay()->confirmTransaction(1, 123));
    }

    public function testCreateNewTransactionPersistsId(): void
    {
        $context = new TransactionContext();
        $context->spaceId = 1;
        $context->merchantReference = 'ORDER-1';
        $context->currencyCode = 'CHF';
        $context->expectedGrandTotal = 100.00;

        $this->consistencyService->method('ensureConsistency')
            ->willReturnCallback(fn (array $items): LineItemCollection => new LineItemCollection(...$items));

        $transaction = new Transaction();
        $transaction->id = 100;
        $transaction->state = State::PENDING;

        $this->gateway->expects($this->once())
            ->method('create')
            ->with($context)
            ->willReturn($transaction);

        $this->persistence->expects($this->once())
            ->method('persist')
            ->with(100);

        $this->service->upsert($context, $this->persistence);
    }

    public function testCreateTransactionAllowsZeroTotal(): void
    {
        $context = new TransactionContext();
        $context->spaceId = 1;
        $context->merchantReference = 'ZERO-TOTAL';
        $context->currencyCode = 'CHF';
        $context->expectedGrandTotal = 0.00;
        $context->lineItems = new LineItemCollection();

        $this->consistencyService->method('ensureConsistency')
            ->willReturnCallback(fn (array $items): LineItemCollection => new LineItemCollection(...$items));

        // Expect delegating to gateway instead of throwing exception
        $expectedTx = new Transaction();
        $expectedTx->id = 100;
        $expectedTx->state = State::PENDING;

        $this->gateway->expects($this->once())
            ->method('create')
            ->with($context)
            ->willReturn($expectedTx);

        $result = $this->service->createTransaction($context);
        $this->assertEquals(100, $result->id);
    }



    public function testCreateWithNegativeTotalAutoFix(): void
    {
        $context = new TransactionContext();
        $context->spaceId = 1;
        $context->merchantReference = 'AUTO-FIX';
        $context->currencyCode = 'CHF';
        $context->expectedGrandTotal = -50.00; // Total 100 - 150 = -50

        $item1 = new LineItem();
        $item1->amountIncludingTax = 100.00;
        $item1->type = LineItem::TYPE_PRODUCT;

        $item2 = new LineItem();
        $item2->amountIncludingTax = -150.00;
        $item2->type = LineItem::TYPE_DISCOUNT;

        $context->lineItems = new LineItemCollection($item1, $item2);

        // Mock sanitization: 100, -150 -> 100, -100
        $sanitizedItem1 = clone $item1;
        $sanitizedItem2 = clone $item2;
        $sanitizedItem2->amountIncludingTax = -100.00;

        $this->consistencyService->expects($this->once())
            ->method('sanitizeNegativeLineItems')
            ->with($context->lineItems->all())
            ->willReturn(new LineItemCollection($sanitizedItem1, $sanitizedItem2));

        // Mock consistency check: 0.00 total
        $this->consistencyService->expects($this->once())
            ->method('ensureConsistency')
            ->with(
                $this->anything(),
                0.00,
                'CHF',
                1,
                null,
            )
            ->willReturnCallback(fn (array $items): LineItemCollection => new LineItemCollection(...$items));

        $transaction = new Transaction();
        $transaction->id = 777;
        $transaction->state = State::PENDING;

        $this->gateway->expects($this->once())
            ->method('create')
            ->willReturn($transaction);

        $result = $this->service->createTransaction($context);

        $this->assertEquals(777, $result->id);
        $this->assertEquals(0.00, $context->expectedGrandTotal);
        $this->assertEquals(-100.00, $context->lineItems->all()[1]->amountIncludingTax);
    }

    public function testGetAvailablePaymentMethodsSortsByName(): void
    {
        $spaceId = 123;
        $transactionId = 456;

        // Same sortOrder, different names — tie-breaker should sort alphabetically
        $methodA = new PaymentMethod(
            id: 1,
            spaceId: $spaceId,
            state: PaymentMethodState::ACTIVE,
            title: new LocalizedString(['en-US' => 'Zeus Payment']),
            description: new LocalizedString(['en-US' => 'Desc']),
            sortOrder: 1,
            imageUrl: null,
        );

        $methodB = new PaymentMethod(
            id: 2,
            spaceId: $spaceId,
            state: PaymentMethodState::ACTIVE,
            title: new LocalizedString(['en-US' => 'Apollo Payment']),
            description: new LocalizedString(['en-US' => 'Desc']),
            sortOrder: 1,
            imageUrl: null,
        );

        // Different sortOrder — primary sort should win regardless of name
        $methodC = new PaymentMethod(
            id: 3,
            spaceId: $spaceId,
            state: PaymentMethodState::ACTIVE,
            title: new LocalizedString(['en-US' => 'AAA First Alphabetically']),
            description: new LocalizedString(null),
            sortOrder: 99,
            imageUrl: null,
        );

        // Gateway returns unsorted
        $this->gateway->method('getAvailablePaymentMethods')
            ->willReturn(new PaymentMethodCollection($methodC, $methodA, $methodB));

        // Default (No Sort) — preserves gateway order
        $default = $this->service->getAvailablePaymentMethods($spaceId, $transactionId, PaymentMethodSorting::DEFAULT)->all();
        $this->assertSame($methodC, $default[0]);
        $this->assertSame($methodA, $default[1]);
        $this->assertSame($methodB, $default[2]);

        // Sorted by Name — primary: sortOrder, secondary: alphabetical title
        $sorted = $this->service->getAvailablePaymentMethods($spaceId, $transactionId, PaymentMethodSorting::NAME)->all();
        $this->assertSame($methodB, $sorted[0]); // sortOrder=1, "Apollo"
        $this->assertSame($methodA, $sorted[1]); // sortOrder=1, "Zeus"
        $this->assertSame($methodC, $sorted[2]); // sortOrder=99, despite alphabetically first
    }

    public function testGetLatestTransactionsDelegatesToGatewayWithDefaults(): void
    {
        $spaceId = 123;
        $limit = 5;
        $tx = new Transaction();
        $tx->state = State::PENDING;
        $expectedResults = new TransactionCollection($tx);

        $this->gateway->expects($this->once())
            ->method('search')
            ->with($this->callback(function (int $argSpaceId) use ($spaceId) {
                return $argSpaceId === $spaceId;
            }), $this->callback(function (TransactionSearchCriteria $argCriteria) use ($limit) {
                return $argCriteria->limit === $limit
                    && $argCriteria->sortField === 'id'
                    && $argCriteria->sortOrder === 'DESC';
            }))
            ->willReturn($expectedResults);

        $this->assertSame($expectedResults, $this->service->getLatestTransactions($spaceId, $limit));
    }

    public function testGetPaymentUrlDelegatesToGateway(): void
    {
        $spaceId = 1;
        $txId = 999;
        $paymentUrl = new PaymentUrl("http://example.com");

        $this->gateway->expects($this->once())
            ->method('getPaymentUrl')
            ->with($spaceId, $txId)
            ->willReturn($paymentUrl);

        $result = $this->service->getPaymentUrl($spaceId, $txId);
        $this->assertSame($paymentUrl, $result);
        $this->assertSame("http://example.com", $result->value);
    }

    public function testGetTransactionDelegatesToGateway(): void
    {
        $spaceId = 1;
        $txId = 999;
        $expectedTx = new Transaction();
        $expectedTx->state = State::PENDING;

        $this->gateway->expects($this->once())
            ->method('get')
            ->with($spaceId, $txId)
            ->willReturn($expectedTx);

        $this->assertSame($expectedTx, $this->service->getTransaction($spaceId, $txId));
    }

    public function testNonRetryableUpdateFailureFallsBackToCreateWithoutRetrying(): void
    {
        $context = new TransactionContext();
        $context->spaceId = 1;
        $context->transactionId = 123;
        $context->merchantReference = 'TEST-NON-RETRYABLE';
        $context->expectedGrandTotal = 100.00;
        $context->currencyCode = 'CHF';
        $context->language = 'en-US';
        $context->customerId = 'TEST-CUST-1';
        $context->lineItems = new LineItemCollection();
        $context->successUrl = new Url('https://example.com/success');
        $context->failedUrl = new Url('https://example.com/fail');
        $context->billingAddress = new Address();
        $context->personalDetails = new PersonalDetails(familyName: 'User', givenName: 'Test');

        // A domain exception that is not marked retryable: retrying would only repeat the rejection.
        $failure = new TransactionException(
            '[400] The request was rejected.',
            new LocalizedString('The request was rejected.'),
        );

        $this->gateway->expects($this->once())
            ->method('find')
            ->willReturn($this->pendingTransaction(5));
        $this->gateway->expects($this->once())
            ->method('update')
            ->willThrowException($failure);

        $created = new Transaction();
        $created->id = 999;
        $created->state = State::PENDING;

        $this->gateway->expects($this->once())
            ->method('create')
            ->willReturn($created);
        $this->persistence->expects($this->once())
            ->method('persist')
            ->with(999);

        $result = $this->serviceWithoutRetryDelay()->upsert($context, $this->persistence);

        $this->assertSame(999, $result->id);
    }

    public function testRetryableFailureThatIsNotAConflictIsNotRetried(): void
    {
        $context = new TransactionContext();
        $context->spaceId = 1;
        $context->transactionId = 123;
        $context->merchantReference = 'TEST-CONNECTION-FAILURE';
        $context->expectedGrandTotal = 100.00;
        $context->currencyCode = 'CHF';
        $context->language = 'en-US';
        $context->customerId = 'TEST-CUST-1';
        $context->lineItems = new LineItemCollection();
        $context->successUrl = new Url('https://example.com/success');
        $context->failedUrl = new Url('https://example.com/fail');
        $context->billingAddress = new Address();
        $context->personalDetails = new PersonalDetails(familyName: 'User', givenName: 'Test');

        // A lost connection is retryable in general, but says nothing about the transaction itself.
        $failure = (new TransactionException(
            'Operation update failed: connection refused.',
            new LocalizedString('Unable to update the transaction.'),
        ))->withRetryable(true);

        $this->gateway->expects($this->once())
            ->method('find')
            ->willReturn($this->pendingTransaction(5));
        $this->gateway->expects($this->once())
            ->method('update')
            ->willThrowException($failure);

        $created = new Transaction();
        $created->id = 999;
        $created->state = State::PENDING;

        $this->gateway->expects($this->once())
            ->method('create')
            ->willReturn($created);
        $this->persistence->expects($this->once())
            ->method('persist')
            ->with(999);

        $result = $this->serviceWithoutRetryDelay()->upsert($context, $this->persistence);

        $this->assertSame(999, $result->id);
    }

    public function testRetryableUpdateFailureIsRetriedInsteadOfCreatingNewTransaction(): void
    {
        $context = new TransactionContext();
        $context->spaceId = 1;
        $context->transactionId = 123;

        $transactionA = $this->pendingTransaction(5);
        $transactionB = $this->pendingTransaction(6);
        $transactionC = $this->pendingTransaction(7);

        $failure = (new TransactionException(
            '[409] The request conflicts with another request.',
            new LocalizedString('The request conflicts with another request.'),
        ))->withConflict();

        $this->gateway->expects($this->exactly(2))
            ->method('find')
            ->willReturnOnConsecutiveCalls($transactionA, $transactionB);

        $sentVersions = [];
        $this->gateway->expects($this->exactly(2))
            ->method('update')
            ->willReturnCallback(function (int $id, int $version) use (&$sentVersions, $failure, $transactionC): Transaction {
                $sentVersions[] = $version;
                if (count($sentVersions) === 1) {
                    throw $failure;
                }
                return $transactionC;
            });

        $this->gateway->expects($this->never())->method('create');
        $this->persistence->expects($this->never())->method('persist');

        $result = $this->serviceWithoutRetryDelay()->upsert($context, $this->persistence);

        $this->assertSame([5, 6], $sentVersions);
        $this->assertSame(123, $result->id);
    }

    public function testRetryableUpdateFailureOnEveryAttemptKeepsExistingTransaction(): void
    {
        $context = new TransactionContext();
        $context->spaceId = 1;
        $context->transactionId = 123;

        $transaction = $this->pendingTransaction(5);

        $failure = (new TransactionException(
            '[409] The request conflicts with another request.',
            new LocalizedString('The request conflicts with another request.'),
        ))->withConflict();

        $this->gateway->expects($this->exactly(5))
            ->method('find')
            ->willReturn($transaction);
        $this->gateway->expects($this->exactly(5))
            ->method('update')
            ->willThrowException($failure);

        $this->gateway->expects($this->never())->method('create');
        $this->persistence->expects($this->never())->method('persist');

        $result = $this->serviceWithoutRetryDelay()->upsert($context, $this->persistence);

        $this->assertSame(123, $result->id);
    }

    public function testSearchTransactionsDelegatesToGateway(): void
    {
        $spaceId = 123;
        $criteria = new TransactionSearchCriteria();
        $tx = new Transaction();
        $tx->state = State::PENDING;
        $expectedResults = new TransactionCollection($tx);

        $this->gateway->expects($this->once())
            ->method('search')
            ->with($spaceId, $criteria)
            ->willReturn($expectedResults);

        $this->assertSame($expectedResults, $this->service->searchTransactions($spaceId, $criteria));
    }

    public function testTransactionLeavingPendingBetweenAttemptsFallsBackToCreate(): void
    {
        $context = new TransactionContext();
        $context->spaceId = 1;
        $context->transactionId = 123;
        $context->merchantReference = 'TEST-NO-LONGER-PENDING';
        $context->expectedGrandTotal = 100.00;
        $context->currencyCode = 'CHF';
        $context->language = 'en-US';
        $context->customerId = 'TEST-CUST-1';
        $context->lineItems = new LineItemCollection();
        $context->successUrl = new Url('https://example.com/success');
        $context->failedUrl = new Url('https://example.com/fail');
        $context->billingAddress = new Address();
        $context->personalDetails = new PersonalDetails(familyName: 'User', givenName: 'Test');

        $conflict = (new TransactionException(
            '[409] The request conflicts with another request.',
            new LocalizedString('The request conflicts with another request.'),
        ))->withConflict();

        // The first attempt conflicts; by the second read another request has confirmed the transaction.
        $confirmed = $this->pendingTransaction(6);
        $confirmed->state = State::CONFIRMED;

        $this->gateway->expects($this->exactly(2))
            ->method('find')
            ->willReturnOnConsecutiveCalls($this->pendingTransaction(5), $confirmed);
        $this->gateway->expects($this->once())
            ->method('update')
            ->willThrowException($conflict);

        $created = new Transaction();
        $created->id = 999;
        $created->state = State::PENDING;

        $this->gateway->expects($this->once())
            ->method('create')
            ->willReturn($created);
        $this->persistence->expects($this->once())
            ->method('persist')
            ->with(999);

        $result = $this->serviceWithoutRetryDelay()->upsert($context, $this->persistence);

        $this->assertSame(999, $result->id);
    }

    public function testUpdateExistingTransactionDoesNotPersist(): void
    {
        $context = new TransactionContext();
        $context->spaceId = 1;
        $context->transactionId = 123;

        // Mock Find (Return Domain Object)
        $domainTx = new Transaction();
        $domainTx->id = 123;
        $domainTx->version = 5;
        $domainTx->state = State::PENDING;

        $this->gateway->expects($this->once())
            ->method('find')
            ->willReturn($domainTx);

        // Mock Update (Pass ID 123, Version 5)
        $updateResult = new Transaction();
        $updateResult->id = 123;
        $updateResult->version = 6;
        $updateResult->state = State::PENDING;

        $this->gateway->expects($this->once())
            ->method('update')
            ->with(123, 5, $context)
            ->willReturn($updateResult);

        $this->persistence->expects($this->never())->method('persist');

        $result = $this->service->upsert($context, $this->persistence);

        $this->assertEquals(123, $result->id);
    }

    public function testUpdateFailureFallsBackToCreate(): void
    {
        // Setup a FULLY valid Context
        $context = new TransactionContext();
        $context->spaceId = 1;
        $context->transactionId = 123;
        $context->merchantReference = 'TEST-FALLBACK';
        $context->expectedGrandTotal = 100.00;
        $context->currencyCode = 'CHF';
        $context->language = 'en-US';
        $context->customerId = 'TEST-CUST-1';
        $context->lineItems = new LineItemCollection();
        $context->successUrl = new Url('https://example.com/success');
        $context->failedUrl = new Url('https://example.com/fail');

        $context->billingAddress = new Address();
        $context->personalDetails = new PersonalDetails(familyName: 'User', givenName: 'Test');

        // Mock Find
        $domainTx = new Transaction();
        $domainTx->id = 123;
        $domainTx->version = 1;
        $domainTx->state = State::PENDING;

        $this->gateway->expects($this->once())
            ->method('find')
            ->with(1, 123)
            ->willReturn($domainTx);

        // Mock Update (FAILURE)
        $this->gateway->expects($this->once())
            ->method('update')
            ->with(123, 1, $context)
            ->willThrowException(new \Exception("Update failed"));

        // Mock Create (Fallback)
        $fallbackResult = new Transaction();
        $fallbackResult->id = 999;
        $fallbackResult->state = State::PENDING;

        $this->gateway->expects($this->once())
            ->method('create')
            ->with($context)
            ->willReturn($fallbackResult);

        // Expect Persistence (New ID)
        $this->persistence->expects($this->once())
            ->method('persist')
            ->with(999);

        // Execute
        $result = $this->service->upsert($context, $this->persistence);

        // Assert
        $this->assertEquals(999, $result->id);
    }

    public function testUpdateRetriesPauseForARandomTimeAroundTheBaseDelay(): void
    {
        $context = new TransactionContext();
        $context->spaceId = 1;
        $context->transactionId = 123;

        $this->gateway->method('find')->willReturn($this->pendingTransaction(5));
        $this->gateway->expects($this->exactly(5))->method('update')->willThrowException(
            (new TransactionException('[409] Conflict.', new LocalizedString('Conflict.')))->withConflict(),
        );

        $pauses = [];
        $this->serviceRecordingPauses($pauses)->upsert($context, $this->persistence);

        $this->assertCount(4, $pauses, 'One pause between each of the five attempts.');
        $this->assertGreaterThan(1, count(array_unique($pauses)), 'The pause should differ between attempts.');
        foreach ($pauses as $pause) {
            $this->assertGreaterThanOrEqual(200, $pause);
            $this->assertLessThanOrEqual(600, $pause);
        }
    }

    public function testUpsertWithNegativeTotalAutoFix(): void
    {
        $context = new TransactionContext();
        $context->spaceId = 1;
        $context->transactionId = 123;
        $context->expectedGrandTotal = -20.00;

        $item1 = new LineItem();
        $item1->amountIncludingTax = 80.00;
        $item1->type = LineItem::TYPE_PRODUCT;

        $item2 = new LineItem();
        $item2->amountIncludingTax = -100.00;
        $item2->type = LineItem::TYPE_DISCOUNT;

        $context->lineItems = new LineItemCollection($item1, $item2);

        $existing = new Transaction();
        $existing->id = 123;
        $existing->version = 1;
        $existing->state = State::PENDING;

        $this->gateway->method('find')->willReturn($existing);

        // Expect sanitization
        $this->consistencyService->expects($this->once())
            ->method('sanitizeNegativeLineItems')
            ->willReturn($context->lineItems); // Just return same for simplicity in mock

        $this->gateway->expects($this->once())
            ->method('update')
            ->willReturn($existing);

        $persistenceMock = $this->createMock(TransactionPersistenceInterface::class);

        $this->service->upsert($context, $persistenceMock);

        $this->assertEquals(0.00, $context->expectedGrandTotal);
    }

    private function transactionInState(State $state, int $version = 5): Transaction
    {
        $transaction = $this->pendingTransaction($version);
        $transaction->state = $state;

        return $transaction;
    }
}
