<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\WebServiceAPIV1;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Charge\Attempt\ChargeAttempt;
use WeArePlanet\PluginCore\Charge\Exception\ChargeException;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\ChargeGateway;
use WeArePlanet\PluginCore\Transaction\State;
use WeArePlanet\PluginCore\Transaction\Transaction;
use WeArePlanet\Sdk\Model\ChargeAttempt as SdkChargeAttempt;
use WeArePlanet\Sdk\Model\ChargeAttemptState as SdkChargeAttemptState;
use WeArePlanet\Sdk\Model\CriteriaOperator as SdkCriteriaOperator;
use WeArePlanet\Sdk\Model\EntityQuery as SdkEntityQuery;
use WeArePlanet\Sdk\Model\EntityQueryFilter as SdkEntityQueryFilter;
use WeArePlanet\Sdk\Model\EntityQueryFilterType as SdkEntityQueryFilterType;
use WeArePlanet\Sdk\Model\Label as SdkLabel;
use WeArePlanet\Sdk\Model\LabelDescriptor as SdkLabelDescriptor;
use WeArePlanet\Sdk\Model\Transaction as SdkTransaction;
use WeArePlanet\Sdk\Model\TransactionState as SdkTransactionState;
use WeArePlanet\Sdk\Service\ChargeAttemptService as SdkChargeAttemptService;
use WeArePlanet\Sdk\Service\ChargeFlowService as SdkChargeFlowService;

class ChargeGatewayTest extends TestCase
{
    private const SPACE_ID = 42;
    private const TRANSACTION_ID = 1234;
    private MockObject|SdkChargeFlowService $chargeFlowService;

    private ChargeGateway $gateway;
    private MockObject|LoggerInterface $logger;
    private MockObject|SdkProvider $sdkProvider;
    private MockObject|SdkChargeAttemptService $service;

    /**
     * Builds an SDK charge attempt carrying three labels: two in group 4, one in group 7.
     */
    private function makeSdkChargeAttempt(): SdkChargeAttempt
    {
        $chargeAttempt = new SdkChargeAttempt();
        $chargeAttempt->setId(777);
        $chargeAttempt->setState(SdkChargeAttemptState::SUCCESSFUL);
        $chargeAttempt->setLabels([
            $this->makeSdkLabel(1001, 'VISA', 4),
            $this->makeSdkLabel(1002, '123456', 4),
            $this->makeSdkLabel(1003, 'AUTH-9', 7),
        ]);

        return $chargeAttempt;
    }

    private function makeSdkLabel(int $descriptorId, string $content, ?int $groupId): SdkLabel
    {
        $descriptor = new SdkLabelDescriptor();
        $descriptor->setId($descriptorId);
        $descriptor->setGroup($groupId);

        $label = new SdkLabel();
        $label->setDescriptor($descriptor);
        $label->setContentAsString($content);

        return $label;
    }

    private function makeSdkTransaction(): SdkTransaction
    {
        $transaction = new SdkTransaction();
        $transaction->setId(self::TRANSACTION_ID);
        $transaction->setLinkedSpaceId(self::SPACE_ID);
        $transaction->setState(SdkTransactionState::PROCESSING);

        return $transaction;
    }

    protected function setUp(): void
    {
        $this->sdkProvider = $this->createMock(SdkProvider::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = $this->createMock(SdkChargeAttemptService::class);
        $this->chargeFlowService = $this->createMock(SdkChargeFlowService::class);

        // The gateway resolves two SDK services now: the charge attempt search and the
        // charge flow it is applied through.
        $this->sdkProvider->method('getService')->willReturnMap([
            [SdkChargeAttemptService::class, $this->service],
            [SdkChargeFlowService::class, $this->chargeFlowService],
        ]);

        $this->gateway = new ChargeGateway(
            $this->sdkProvider,
            $this->logger,
        );
    }

    public function testApplyFlowChargesTheTransactionAndMapsTheResult(): void
    {
        $this->chargeFlowService->expects($this->once())
            ->method('applyFlow')
            ->with(self::SPACE_ID, self::TRANSACTION_ID)
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
        $this->chargeFlowService->method('applyFlow')->willThrowException($sdkException);

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('failed'),
                $this->callback(function (array $context) use ($sdkException): bool {
                    $this->assertSame('applyFlow', $context['operation']);
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

    public function testApplyFlowRejectsAResponseThatIsNotATransaction(): void
    {
        $this->chargeFlowService->method('applyFlow')->willReturn(null);

        $this->expectException(ChargeException::class);
        $this->gateway->applyFlow(self::SPACE_ID, self::TRANSACTION_ID);
    }

    public function testChargeExceptionKeepsTheSdkFailureAsPrevious(): void
    {
        $sdkException = new \Exception('SDK unavailable');
        $this->service->method('search')->willThrowException($sdkException);

        try {
            $this->gateway->findAllAttemptsByTransaction(self::SPACE_ID, self::TRANSACTION_ID);
            $this->fail('Expected a ChargeException.');
        } catch (ChargeException $e) {
            $this->assertSame($sdkException, $e->getPrevious());
            $this->assertStringContainsString((string)self::TRANSACTION_ID, $e->getMessage());
        }
    }

    public function testFindAllAttemptsByTransactionFiltersOnTransactionOnlyAndDoesNotCapTheResults(): void
    {
        $this->service->expects($this->once())
            ->method('search')
            ->with(
                self::SPACE_ID,
                $this->callback(function (SdkEntityQuery $query): bool {
                    // No cap: the caller is promised every attempt.
                    $this->assertNull($query->getNumberOfEntities());

                    // A single leaf on the transaction. Filtering by state would move
                    // the success rule back into the adapter, where it must not live.
                    $filter = $query->getFilter();
                    $this->assertInstanceOf(SdkEntityQueryFilter::class, $filter);
                    $this->assertSame(SdkEntityQueryFilterType::LEAF, $filter->getType());
                    $this->assertSame(SdkCriteriaOperator::EQUALS, $filter->getOperator());
                    $this->assertSame('charge.transaction.id', $filter->getFieldName());
                    $this->assertSame(self::TRANSACTION_ID, $filter->getValue());

                    return true;
                }),
            )
            ->willReturn([$this->makeSdkChargeAttempt()]);

        $this->gateway->findAllAttemptsByTransaction(self::SPACE_ID, self::TRANSACTION_ID);
    }

    public function testFindAllAttemptsByTransactionLogsStructuredContextAndWrapsSdkFailures(): void
    {
        $sdkException = new \Exception('SDK unavailable');
        $this->service->method('search')->willThrowException($sdkException);

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

    public function testFindAllAttemptsByTransactionMapsTheAttemptAndItsLabels(): void
    {
        $this->service->method('search')->willReturn([$this->makeSdkChargeAttempt()]);

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
        // This API reports the descriptor group as a bare ID, so no name is available.
        $this->assertNull($first->groupName);
    }

    public function testFindAllAttemptsByTransactionReturnsAnEmptyListAndLogsDebugWhenNoneExist(): void
    {
        $this->service->method('search')->willReturn([]);

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

        $this->service->method('search')
            ->willReturn([$failed, $processing, $this->makeSdkChargeAttempt()]);

        $attempts = $this->gateway->findAllAttemptsByTransaction(self::SPACE_ID, self::TRANSACTION_ID);

        $this->assertCount(3, $attempts);
        $this->assertSame([775, 776, 777], array_map(static fn ($a) => $a->id, $attempts));
        $this->assertSame([false, false, true], array_map(static fn ($a) => $a->isSuccessful(), $attempts));
    }

    public function testFindAllAttemptsByTransactionSkipsLabelsWithoutADescriptor(): void
    {
        $chargeAttempt = new SdkChargeAttempt();
        $chargeAttempt->setId(777);
        $chargeAttempt->setState(SdkChargeAttemptState::SUCCESSFUL);
        $chargeAttempt->setLabels([
            new SdkLabel(),
            $this->makeSdkLabel(1001, 'VISA', 4),
        ]);

        $this->service->method('search')->willReturn([$chargeAttempt]);

        $attempts = $this->gateway->findAllAttemptsByTransaction(self::SPACE_ID, self::TRANSACTION_ID);

        $this->assertCount(1, $attempts);
        $this->assertCount(1, $attempts[0]->labels);
        $this->assertSame(1001, $attempts[0]->labels[0]->descriptorId);
    }

    public function testGetLabelReturnsTheLabelForTheGivenDescriptorId(): void
    {
        $this->service->method('search')->willReturn([$this->makeSdkChargeAttempt()]);

        $chargeAttempt = $this->gateway->findAllAttemptsByTransaction(self::SPACE_ID, self::TRANSACTION_ID)[0];
        $this->assertInstanceOf(ChargeAttempt::class, $chargeAttempt);

        $label = $chargeAttempt->getLabel(1002);
        $this->assertNotNull($label);
        $this->assertSame('123456', $label->content);

        $this->assertNull($chargeAttempt->getLabel(9999));
    }

    public function testGetLabelsByGroupReturnsOnlyTheLabelsOfThatGroup(): void
    {
        $this->service->method('search')->willReturn([$this->makeSdkChargeAttempt()]);

        $chargeAttempt = $this->gateway->findAllAttemptsByTransaction(self::SPACE_ID, self::TRANSACTION_ID)[0];
        $this->assertInstanceOf(ChargeAttempt::class, $chargeAttempt);

        $groupFour = $chargeAttempt->getLabelsByGroup('4');
        $this->assertCount(2, $groupFour);
        $this->assertSame([1001, 1002], array_map(static fn ($label) => $label->descriptorId, $groupFour));

        $this->assertCount(1, $chargeAttempt->getLabelsByGroup('7'));
        $this->assertSame([], $chargeAttempt->getLabelsByGroup('99'));
    }
}
