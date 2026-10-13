<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\WebServiceAPIV2;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\TransactionCompletionGateway;
use WeArePlanet\PluginCore\Transaction\Completion\State;
use WeArePlanet\PluginCore\Transaction\Completion\TransactionCompletion;
use WeArePlanet\PluginCore\Transaction\Void\State as VoidState;
use WeArePlanet\PluginCore\Transaction\Void\TransactionVoid;
use WeArePlanet\Sdk\Model\FailureReason as SdkFailureReason;
use WeArePlanet\Sdk\Model\Label as SdkLabel;
use WeArePlanet\Sdk\Model\LabelDescriptor as SdkLabelDescriptor;
use WeArePlanet\Sdk\Model\LineItem as SdkLineItem;
use WeArePlanet\Sdk\Model\LineItemType as SdkLineItemType;
use WeArePlanet\Sdk\Model\TransactionCompletion as SdkTransactionCompletion;
use WeArePlanet\Sdk\Model\TransactionCompletionState as SdkTransactionCompletionState;
use WeArePlanet\Sdk\Model\TransactionVoid as SdkTransactionVoid;
use WeArePlanet\Sdk\Model\TransactionVoidState as SdkTransactionVoidState;
use WeArePlanet\Sdk\Service\TransactionCompletionsService as SdkTransactionCompletionsService;
use WeArePlanet\Sdk\Service\TransactionsService as SdkTransactionsService;

/**
 * Tests the SDK v2 implementation of the transaction completion gateway.
 *
 * Verifies that SDK response structures (completions, voids, and their failure reasons)
 * are properly mapped to domain objects.
 */
class TransactionCompletionGatewayTest extends TestCase
{
    private TransactionCompletionGateway $gateway;
    private MockObject|SdkProvider $sdkProvider;
    private MockObject|SdkTransactionsService $transactionsService;
    private MockObject|SdkTransactionCompletionsService $transactionCompletionsService;

    protected function setUp(): void
    {
        $this->sdkProvider = $this->createMock(SdkProvider::class);
        $this->transactionsService = $this->createMock(SdkTransactionsService::class);
        $this->transactionCompletionsService = $this->createMock(SdkTransactionCompletionsService::class);

        $this->sdkProvider->method('getService')->willReturnMap([
            [SdkTransactionsService::class, $this->transactionsService],
            [SdkTransactionCompletionsService::class, $this->transactionCompletionsService],
        ]);

        $this->gateway = new TransactionCompletionGateway($this->sdkProvider, $this->createMock(LoggerInterface::class));
    }

    /**
     * Verifies that capture maps an SDK TransactionCompletion to the domain entity when successful.
     */
    public function testCaptureReturnsCompletion(): void
    {
        $spaceId = 1;
        $transactionId = 2;

        $sdkCompletion = new SdkTransactionCompletion();
        $sdkCompletion->setId(10);
        $sdkCompletion->setLinkedTransaction($transactionId);
        $sdkCompletion->setState(SdkTransactionCompletionState::SUCCESSFUL);

        $this->transactionsService->expects($this->once())
            ->method('postPaymentTransactionsIdCompleteOnline')
            ->with($transactionId, $spaceId)
            ->willReturn($sdkCompletion);

        $result = $this->gateway->capture($spaceId, $transactionId);

        $this->assertInstanceOf(TransactionCompletion::class, $result);
        $this->assertEquals(10, $result->id);
        $this->assertEquals($transactionId, $result->linkedTransactionId);
        $this->assertEquals(State::SUCCESSFUL, $result->state);
        $this->assertNull($result->failureReason);
    }

    /**
     * Verifies that a failure reason is properly extracted from the SDK completion response
     * and mapped to a localized string on the domain entity.
     */
    public function testCaptureMapsFailureReason(): void
    {
        $spaceId = 1;
        $transactionId = 2;

        $failureReason = new SdkFailureReason();
        $failureReason->setDescription(['en-US' => 'Insufficient funds']);

        $sdkCompletion = new SdkTransactionCompletion();
        $sdkCompletion->setId(10);
        $sdkCompletion->setLinkedTransaction($transactionId);
        $sdkCompletion->setState(SdkTransactionCompletionState::FAILED);
        $sdkCompletion->setFailureReason($failureReason);

        $this->transactionsService->expects($this->once())
            ->method('postPaymentTransactionsIdCompleteOnline')
            ->with($transactionId, $spaceId)
            ->willReturn($sdkCompletion);

        $result = $this->gateway->capture($spaceId, $transactionId);

        $this->assertNotNull($result->failureReason);
        $this->assertEquals('Insufficient funds', $result->failureReason->localize('en-US'));
    }

    /**
     * Verifies that a completion's line items are mapped to domain LineItems, not
     * handed back as SDK models.
     */
    public function testGetMapsLineItemsToDomainLineItems(): void
    {
        $sdkItem = new SdkLineItem();
        $sdkItem->setUniqueId('discount-1');
        $sdkItem->setSku('SUMMER-SALE');
        $sdkItem->setName('Summer sale');
        $sdkItem->setQuantity(1.0);
        $sdkItem->setAmountIncludingTax(-5.0);
        $sdkItem->setUnitPriceIncludingTax(-5.0);
        $sdkItem->setType(SdkLineItemType::DISCOUNT);

        $sdkCompletion = new SdkTransactionCompletion();
        $sdkCompletion->setId(10);
        $sdkCompletion->setLinkedTransaction(2);
        $sdkCompletion->setState(SdkTransactionCompletionState::SUCCESSFUL);
        $sdkCompletion->setLineItems([$sdkItem]);

        $this->transactionCompletionsService->method('getPaymentTransactionsCompletionsId')->willReturn($sdkCompletion);

        $result = $this->gateway->get(1, 10);

        $this->assertCount(1, $result->lineItems);
        $this->assertInstanceOf(LineItem::class, $result->lineItems[0]);
        $this->assertSame('discount-1', $result->lineItems[0]->uniqueId);
        $this->assertSame(LineItem::TYPE_DISCOUNT, $result->lineItems[0]->type);
    }

    /**
     * Verifies that a completion without line items reports null, as documented.
     */
    public function testGetLeavesLineItemsNullWhenThePayloadCarriesNone(): void
    {
        $sdkCompletion = new SdkTransactionCompletion();
        $sdkCompletion->setId(10);
        $sdkCompletion->setLinkedTransaction(2);
        $sdkCompletion->setState(SdkTransactionCompletionState::SUCCESSFUL);

        $this->transactionCompletionsService->method('getPaymentTransactionsCompletionsId')->willReturn($sdkCompletion);

        $this->assertNull($this->gateway->get(1, 10)->lineItems);
    }

    /**
     * Verifies that reading a completion by ID requests and maps its labels.
     */
    public function testGetRequestsAndMapsLabels(): void
    {
        $spaceId = 1;
        $completionId = 10;

        $descriptor = new SdkLabelDescriptor();
        $descriptor->setId(1001);
        $sdkLabel = new SdkLabel();
        $sdkLabel->setDescriptor($descriptor);
        $sdkLabel->setContentAsString('VISA');

        $sdkCompletion = new SdkTransactionCompletion();
        $sdkCompletion->setId($completionId);
        $sdkCompletion->setLinkedTransaction(2);
        $sdkCompletion->setState(SdkTransactionCompletionState::SUCCESSFUL);
        $sdkCompletion->setLabels([$sdkLabel]);

        $this->transactionCompletionsService->expects($this->once())
            ->method('getPaymentTransactionsCompletionsId')
            ->with($completionId, $spaceId, ['labels', 'lineItems'])
            ->willReturn($sdkCompletion);

        $result = $this->gateway->get($spaceId, $completionId);

        $this->assertCount(1, $result->labels);
        $this->assertSame(1001, $result->labels[0]->descriptorId);
        $this->assertSame('VISA', $result->labels[0]->content);
    }

    /**
     * Verifies that void operation maps the SDK TransactionVoid response to a TransactionVoid domain entity on success.
     */
    public function testVoidReturnsTransactionVoid(): void
    {
        $spaceId = 1;
        $transactionId = 2;

        $sdkVoid = new SdkTransactionVoid();
        $sdkVoid->setState(SdkTransactionVoidState::SUCCESSFUL);

        $this->transactionsService->expects($this->once())
            ->method('postPaymentTransactionsIdVoidOnline')
            ->with($transactionId, $spaceId)
            ->willReturn($sdkVoid);

        $result = $this->gateway->void($spaceId, $transactionId);

        $this->assertInstanceOf(TransactionVoid::class, $result);
        $this->assertEquals(VoidState::SUCCESSFUL, $result->state);
        $this->assertNull($result->failureReason);
    }

    /**
     * Verifies that a void failure reason from the SDK is correctly mapped to the domain
     * void object as a localized string.
     */
    public function testVoidMapsFailureReason(): void
    {
        $spaceId = 1;
        $transactionId = 2;

        $failureReason = new SdkFailureReason();
        $failureReason->setDescription(['en-US' => 'Void rejected by gateway']);

        $sdkVoid = new SdkTransactionVoid();
        $sdkVoid->setState(SdkTransactionVoidState::FAILED);
        $sdkVoid->setFailureReason($failureReason);

        $this->transactionsService->expects($this->once())
            ->method('postPaymentTransactionsIdVoidOnline')
            ->with($transactionId, $spaceId)
            ->willReturn($sdkVoid);

        $result = $this->gateway->void($spaceId, $transactionId);

        $this->assertInstanceOf(TransactionVoid::class, $result);
        $this->assertEquals(VoidState::FAILED, $result->state);
        $this->assertNotNull($result->failureReason);
        $this->assertEquals('Void rejected by gateway', $result->failureReason->localize('en-US'));
    }

    public function testFindExpandsLabelsAndLineItems(): void
    {
        $sdkCompletion = new SdkTransactionCompletion();
        $sdkCompletion->setId(5);
        $sdkCompletion->setLinkedTransaction(2);
        $sdkCompletion->setState(SdkTransactionCompletionState::SUCCESSFUL);

        $this->transactionCompletionsService->expects($this->once())
            ->method('getPaymentTransactionsCompletionsId')
            ->with(5, 1, ['labels', 'lineItems'])
            ->willReturn($sdkCompletion);

        $this->assertNotNull($this->gateway->find(1, 5));
    }
}
