<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\WebServiceAPIV2;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Charge\Attempt\ChargeAttempt;
use WeArePlanet\PluginCore\Charge\Exception\ChargeException;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\ChargeGateway;
use WeArePlanet\PluginCore\Transaction\State;
use WeArePlanet\PluginCore\Transaction\Transaction;
use WeArePlanet\Sdk\Model\ChargeAttempt as SdkChargeAttempt;
use WeArePlanet\Sdk\Model\ChargeAttemptSearchResponse as SdkChargeAttemptSearchResponse;
use WeArePlanet\Sdk\Model\ChargeAttemptState as SdkChargeAttemptState;
use WeArePlanet\Sdk\Model\Label as SdkLabel;
use WeArePlanet\Sdk\Model\LabelDescriptor as SdkLabelDescriptor;
use WeArePlanet\Sdk\Model\LabelDescriptorGroup as SdkLabelDescriptorGroup;
use WeArePlanet\Sdk\Model\RestApiErrorResponse as SdkRestApiErrorResponse;
use WeArePlanet\Sdk\Model\Transaction as SdkTransaction;
use WeArePlanet\Sdk\Model\TransactionState as SdkTransactionState;
use WeArePlanet\Sdk\Service\ChargeAttemptsService as SdkChargeAttemptsService;
use WeArePlanet\Sdk\Service\TransactionsService as SdkTransactionsService;

class ChargeGatewayTest extends TestCase
{
    private const SPACE_ID = 42;
    private const TRANSACTION_ID = 1234;

    private ChargeGateway $gateway;
    private MockObject|LoggerInterface $logger;
    private MockObject|SdkProvider $sdkProvider;
    private MockObject|SdkChargeAttemptsService $service;
    private MockObject|SdkTransactionsService $transactionsService;

    protected function setUp(): void
    {
        $this->sdkProvider = $this->createMock(SdkProvider::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = $this->createMock(SdkChargeAttemptsService::class);
        $this->transactionsService = $this->createMock(SdkTransactionsService::class);

        // The gateway resolves two SDK services now: the charge attempt search, and the
        // transaction service this API hangs the charge flow off.
        $this->sdkProvider->method('getService')->willReturnMap([
            [SdkChargeAttemptsService::class, $this->service],
            [SdkTransactionsService::class, $this->transactionsService],
        ]);

        $this->gateway = new ChargeGateway(
            $this->sdkProvider,
            $this->logger,
        );
    }

    public function testFindAllAttemptsByTransactionFiltersOnTransactionOnlyAndRequestsAFullPage(): void
    {
        $this->service->expects($this->once())
            ->method('getPaymentChargeAttemptsSearch')
            ->with(
                self::SPACE_ID,
                null,
                100,
                0,
                null,
                // No state term: filtering by state would move the success rule back
                // into the adapter, where it must not live.
                'charge.transaction.id:' . self::TRANSACTION_ID,
            )
            ->willReturn($this->makeSearchResponse([$this->makeSdkChargeAttempt()]));

        $this->gateway->findAllAttemptsByTransaction(self::SPACE_ID, self::TRANSACTION_ID);
    }

    public function testFindAllAttemptsByTransactionMapsTheAttemptAndItsLabels(): void
    {
        $this->service->method('getPaymentChargeAttemptsSearch')
            ->willReturn($this->makeSearchResponse([$this->makeSdkChargeAttempt()]));

        $attempts = $this->gateway->findAllAttemptsByTransaction(self::SPACE_ID, self::TRANSACTION_ID);

        $this->assertCount(1, $attempts);
        $chargeAttempt = $attempts[0];
        $this->assertInstanceOf(ChargeAttempt::class, $chargeAttempt);
        $this->assertSame(777, $chargeAttempt->id);
        $this->assertTrue($chargeAttempt->isSuccessful());
        $this->assertSame(SdkChargeAttemptState::SUCCESSFUL, $chargeAttempt->state);
        $this->assertCount(3, $chargeAttempt->labels);

        $first = $chargeAttempt->labels[0];
        $this->assertSame(1001, $first->descriptorId);
        $this->assertSame('VISA', $first->content);
        $this->assertSame('4', $first->groupId);
        // This API returns the descriptor group inline, so its localized name is available.
        $this->assertNotNull($first->groupName);
        $this->assertSame('Card', $first->groupName->localize('en-US'));
    }

    public function testGetLabelReturnsTheLabelForTheGivenDescriptorId(): void
    {
        $this->service->method('getPaymentChargeAttemptsSearch')
            ->willReturn($this->makeSearchResponse([$this->makeSdkChargeAttempt()]));

        $chargeAttempt = $this->gateway->findAllAttemptsByTransaction(self::SPACE_ID, self::TRANSACTION_ID)[0];
        $this->assertInstanceOf(ChargeAttempt::class, $chargeAttempt);

        $label = $chargeAttempt->getLabel(1002);
        $this->assertNotNull($label);
        $this->assertSame('123456', $label->content);

        $this->assertNull($chargeAttempt->getLabel(9999));
    }

    public function testGetLabelsByGroupReturnsOnlyTheLabelsOfThatGroup(): void
    {
        $this->service->method('getPaymentChargeAttemptsSearch')
            ->willReturn($this->makeSearchResponse([$this->makeSdkChargeAttempt()]));

        $chargeAttempt = $this->gateway->findAllAttemptsByTransaction(self::SPACE_ID, self::TRANSACTION_ID)[0];
        $this->assertInstanceOf(ChargeAttempt::class, $chargeAttempt);

        $groupFour = $chargeAttempt->getLabelsByGroup('4');
        $this->assertCount(2, $groupFour);
        $this->assertSame([1001, 1002], array_map(static fn ($label) => $label->descriptorId, $groupFour));

        $this->assertCount(1, $chargeAttempt->getLabelsByGroup('7'));
        $this->assertSame([], $chargeAttempt->getLabelsByGroup('99'));
    }

    public function testFindAllAttemptsByTransactionSkipsLabelsWithoutADescriptor(): void
    {
        $chargeAttempt = new SdkChargeAttempt();
        $chargeAttempt->setId(777);
        $chargeAttempt->setState(SdkChargeAttemptState::SUCCESSFUL);
        $chargeAttempt->setLabels([
            new SdkLabel(),
            $this->makeSdkLabel(1001, 'VISA', 4, 'Card'),
        ]);

        $this->service->method('getPaymentChargeAttemptsSearch')
            ->willReturn($this->makeSearchResponse([$chargeAttempt]));

        $attempts = $this->gateway->findAllAttemptsByTransaction(self::SPACE_ID, self::TRANSACTION_ID);

        $this->assertCount(1, $attempts);
        $this->assertCount(1, $attempts[0]->labels);
        $this->assertSame(1001, $attempts[0]->labels[0]->descriptorId);
    }

    public function testFindAllAttemptsByTransactionReturnsEveryAttemptUnfiltered(): void
    {
        // Two failed runs and one success. All three must come back: selecting among
        // them is the domain's job, not this gateway's.
        $failed = new SdkChargeAttempt();
        $failed->setId(775);
        $failed->setState(SdkChargeAttemptState::FAILED);

        $processing = new SdkChargeAttempt();
        $processing->setId(776);
        $processing->setState(SdkChargeAttemptState::PROCESSING);

        $this->service->method('getPaymentChargeAttemptsSearch')
            ->willReturn($this->makeSearchResponse([$failed, $processing, $this->makeSdkChargeAttempt()]));

        $attempts = $this->gateway->findAllAttemptsByTransaction(self::SPACE_ID, self::TRANSACTION_ID);

        $this->assertCount(3, $attempts);
        $this->assertSame([775, 776, 777], array_map(static fn ($a) => $a->id, $attempts));
        $this->assertSame([false, false, true], array_map(static fn ($a) => $a->isSuccessful(), $attempts));
    }

    public function testFindAllAttemptsByTransactionPagesUntilTheApiReportsNoMore(): void
    {
        // This endpoint is paginated. A caller promised the complete list must get the
        // second page too, so the gateway keeps asking while hasMore is true.
        $first = new SdkChargeAttempt();
        $first->setId(775);
        $first->setState(SdkChargeAttemptState::FAILED);

        $this->service->expects($this->exactly(2))
            ->method('getPaymentChargeAttemptsSearch')
            ->willReturnOnConsecutiveCalls(
                $this->makeSearchResponse([$first], true),
                $this->makeSearchResponse([$this->makeSdkChargeAttempt()], false),
            );

        $attempts = $this->gateway->findAllAttemptsByTransaction(self::SPACE_ID, self::TRANSACTION_ID);

        $this->assertCount(2, $attempts);
        $this->assertSame([775, 777], array_map(static fn ($a) => $a->id, $attempts));
    }

    public function testFindAllAttemptsByTransactionReturnsAnEmptyListAndLogsDebugWhenNoneExist(): void
    {
        $this->service->method('getPaymentChargeAttemptsSearch')
            ->willReturn($this->makeSearchResponse([]));

        // An ordinary "nothing to report" outcome belongs at debug, not info.
        $this->logger->expects($this->never())->method('info');

        // call() already logs at debug, so collect every record and pick the one
        // reporting the empty result rather than expecting a single debug call.
        $debugRecords = [];
        $this->logger->method('debug')
            ->willReturnCallback(function (string $message, array $context = []) use (&$debugRecords): void {
                $debugRecords[] = [$message, $context];
            });

        $this->assertSame([], $this->gateway->findAllAttemptsByTransaction(self::SPACE_ID, self::TRANSACTION_ID));

        $empty = array_values(array_filter(
            $debugRecords,
            static fn (array $record): bool => str_contains($record[0], 'No charge attempts found'),
        ));

        $this->assertCount(1, $empty, 'Expected the empty-result record at debug level.');
        $this->assertSame(self::SPACE_ID, $empty[0][1]['spaceId']);
        $this->assertSame(self::TRANSACTION_ID, $empty[0][1]['transactionId']);
    }

    public function testFindAllAttemptsByTransactionLogsStructuredContextAndWrapsSdkFailures(): void
    {
        $sdkException = new \Exception('SDK unavailable');
        $this->service->method('getPaymentChargeAttemptsSearch')->willThrowException($sdkException);

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('failed'),
                $this->callback(function (array $context) use ($sdkException): bool {
                    $this->assertSame('SDK unavailable', $context['errorMessage']);
                    $this->assertSame($sdkException, $context['exception']);
                    $this->assertSame(self::SPACE_ID, $context['spaceId']);
                    $this->assertSame(self::TRANSACTION_ID, $context['transactionId']);

                    return true;
                }),
            );

        $this->expectException(ChargeException::class);
        $this->gateway->findAllAttemptsByTransaction(self::SPACE_ID, self::TRANSACTION_ID);
    }

    public function testChargeExceptionKeepsTheSdkFailureAsPrevious(): void
    {
        $sdkException = new \Exception('SDK unavailable');
        $this->service->method('getPaymentChargeAttemptsSearch')->willThrowException($sdkException);

        try {
            $this->gateway->findAllAttemptsByTransaction(self::SPACE_ID, self::TRANSACTION_ID);
            $this->fail('Expected a ChargeException.');
        } catch (ChargeException $e) {
            $this->assertSame($sdkException, $e->getPrevious());
            $this->assertStringContainsString((string)self::TRANSACTION_ID, $e->getMessage());
        }
    }

    public function testFindAllAttemptsByTransactionFailsWhenTheApiReturnsAnErrorResponse(): void
    {
        $this->service->method('getPaymentChargeAttemptsSearch')
            ->willReturn(new SdkRestApiErrorResponse());

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('unexpected response'),
                $this->callback(function (array $context): bool {
                    $this->assertSame(self::SPACE_ID, $context['spaceId']);
                    $this->assertSame(self::TRANSACTION_ID, $context['transactionId']);
                    $this->assertArrayHasKey('responseType', $context);

                    return true;
                }),
            );

        $this->expectException(ChargeException::class);
        $this->gateway->findAllAttemptsByTransaction(self::SPACE_ID, self::TRANSACTION_ID);
    }

    /**
     * @param list<SdkChargeAttempt> $chargeAttempts
     */
    private function makeSearchResponse(array $chargeAttempts, bool $hasMore = false): SdkChargeAttemptSearchResponse
    {
        $response = new SdkChargeAttemptSearchResponse();
        $response->setData($chargeAttempts);
        // Set explicitly: the gateway pages until this says otherwise.
        $response->setHasMore($hasMore);

        return $response;
    }

    /**
     * Builds an SDK charge attempt carrying three labels: two in group 4, one in group 7.
     */
    private function makeSdkChargeAttempt(): SdkChargeAttempt
    {
        $chargeAttempt = new SdkChargeAttempt();
        $chargeAttempt->setId(777);
        $chargeAttempt->setState(SdkChargeAttemptState::SUCCESSFUL);
        $chargeAttempt->setLabels([
            $this->makeSdkLabel(1001, 'VISA', 4, 'Card'),
            $this->makeSdkLabel(1002, '123456', 4, 'Card'),
            $this->makeSdkLabel(1003, 'AUTH-9', 7, 'Authorisation'),
        ]);

        return $chargeAttempt;
    }

    private function makeSdkTransaction(): SdkTransaction
    {
        $transaction = new SdkTransaction();
        $transaction->setId(self::TRANSACTION_ID);
        $transaction->setLinkedSpaceId(self::SPACE_ID);
        $transaction->setState(SdkTransactionState::PROCESSING);

        return $transaction;
    }

    private function makeSdkLabel(int $descriptorId, string $content, ?int $groupId, ?string $groupName): SdkLabel
    {
        $descriptor = new SdkLabelDescriptor();
        $descriptor->setId($descriptorId);

        if ($groupId !== null) {
            $group = new SdkLabelDescriptorGroup();
            $group->setId($groupId);
            $group->setName(['en-US' => $groupName]);
            $descriptor->setGroup($group);
        }

        $label = new SdkLabel();
        $label->setDescriptor($descriptor);
        $label->setContentAsString($content);

        return $label;
    }

    public function testApplyFlowChargesTheTransactionAndMapsTheResult(): void
    {
        // This API takes the transaction ID first and the space ID second — the
        // reverse of WebServiceAPIV1, which is exactly what this asserts.
        $this->transactionsService->expects($this->once())
            ->method('postPaymentTransactionsIdChargeFlowApply')
            ->with(self::TRANSACTION_ID, self::SPACE_ID)
            ->willReturn($this->makeSdkTransaction());

        $transaction = $this->gateway->applyFlow(self::SPACE_ID, self::TRANSACTION_ID);

        $this->assertInstanceOf(Transaction::class, $transaction);
        $this->assertSame(self::TRANSACTION_ID, $transaction->id);
        $this->assertSame(self::SPACE_ID, $transaction->spaceId);
        // The flow runs asynchronously: this is the state at the moment it was applied.
        $this->assertSame(State::PROCESSING, $transaction->state);
    }

    public function testApplyFlowLogsStructuredContextAndWrapsSdkFailures(): void
    {
        $sdkException = new \Exception('SDK unavailable');
        $this->transactionsService->method('postPaymentTransactionsIdChargeFlowApply')
            ->willThrowException($sdkException);

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('failed'),
                $this->callback(function (array $context) use ($sdkException): bool {
                    $this->assertSame($sdkException, $context['exception']);
                    $this->assertSame(self::SPACE_ID, $context['spaceId']);
                    $this->assertSame(self::TRANSACTION_ID, $context['transactionId']);

                    return true;
                }),
            );

        try {
            $this->gateway->applyFlow(self::SPACE_ID, self::TRANSACTION_ID);
            $this->fail('Expected a ChargeException.');
        } catch (ChargeException $e) {
            $this->assertSame($sdkException, $e->getPrevious());
            $this->assertStringContainsString((string)self::TRANSACTION_ID, $e->getMessage());
        }
    }

    public function testApplyFlowRejectsAnErrorResponseReturnedInsteadOfThrown(): void
    {
        // This SDK answers some non-2xx replies with an error model rather than throwing.
        $this->transactionsService->method('postPaymentTransactionsIdChargeFlowApply')
            ->willReturn(new SdkRestApiErrorResponse());

        $this->expectException(ChargeException::class);
        $this->gateway->applyFlow(self::SPACE_ID, self::TRANSACTION_ID);
    }
}
