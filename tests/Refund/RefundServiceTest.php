<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Refund;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Refund\Exception\InvalidRefundException;
use WeArePlanet\PluginCore\Refund\Refund;
use WeArePlanet\PluginCore\Refund\RefundContext;
use WeArePlanet\PluginCore\Refund\RefundGatewayInterface;
use WeArePlanet\PluginCore\Refund\RefundService;
use WeArePlanet\PluginCore\Refund\State as StateEnum;
use WeArePlanet\PluginCore\Refund\Type as TypeEnum;
use WeArePlanet\PluginCore\Transaction\Transaction;
use WeArePlanet\PluginCore\Transaction\TransactionService;

class RefundServiceTest extends TestCase
{
    private MockObject|RefundGatewayInterface $gateway;
    private MockObject|TransactionService $transactionService;
    private MockObject|LoggerInterface $logger;
    private RefundService $service;

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(RefundGatewayInterface::class);
        $this->transactionService = $this->createMock(TransactionService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new RefundService(
            $this->gateway,
            $this->transactionService,
            $this->logger,
        );
    }

    public function testValidateRefundAmountExceedsTotal(): void
    {
        $spaceId = 1;
        $transactionId = 123;

        $transaction = new Transaction();
        $transaction->id = $transactionId;
        $transaction->authorizedAmount = 100.00;
        $transaction->refundedAmount = 0.00;

        $this->transactionService->method('getTransaction')
            ->willReturn($transaction);

        $context = new RefundContext(
            transactionId: $transactionId,
            amount: 150.00, // Exceeds 100
            merchantReference: 'ref-1',
            type: TypeEnum::MERCHANT_INITIATED_ONLINE,
        );

        $this->expectException(InvalidRefundException::class);
        $this->expectExceptionMessage("Refund amount exceeds the remaining authorized amount.");

        $this->service->createRefund($spaceId, $context);
    }

    public function testValidateRefundLineItemExceedsOriginal(): void
    {
        $spaceId = 1;
        $transactionId = 123;

        $itemA = new LineItem();
        $itemA->uniqueId = 'item-a';
        $itemA->quantity = 1;
        $itemA->amountIncludingTax = 50.00;

        $transaction = new Transaction();
        $transaction->id = $transactionId;
        $transaction->authorizedAmount = 100.00;
        $transaction->lineItems = [$itemA];

        $this->transactionService->method('getTransaction')
            ->willReturn($transaction);

        $context = new RefundContext(
            transactionId: $transactionId,
            amount: 60.00,
            merchantReference: 'ref-1',
            type: TypeEnum::MERCHANT_INITIATED_ONLINE,
            lineItems: [
                ['uniqueId' => 'item-a', 'quantity' => 1, 'amount' => 60.00], // Exceeds 50.00
            ],
        );

        $this->expectException(InvalidRefundException::class);
        $this->expectExceptionMessage("Consistency Error: Total provided refund amount (60.00) does not match the sum of line item reductions (50.00).");

        $this->service->createRefund($spaceId, $context);
    }

    public function testSuccessfulFullRefund(): void
    {
        $spaceId = 1;
        $transactionId = 123;

        $transaction = new Transaction();
        $transaction->id = $transactionId;
        $transaction->authorizedAmount = 100.00;

        $this->transactionService->method('getTransaction')
            ->willReturn($transaction);

        $context = new RefundContext(
            transactionId: $transactionId,
            amount: 100.00,
            merchantReference: 'ref-full',
            type: TypeEnum::MERCHANT_INITIATED_ONLINE,
        );

        $expectedRefund = new Refund();
        $expectedRefund->id = 777;
        $expectedRefund->state = StateEnum::SUCCESSFUL;

        $this->gateway->expects($this->once())
            ->method('refund')
            ->with($spaceId, $context)
            ->willReturn($expectedRefund);

        $result = $this->service->createRefund($spaceId, $context);

        $this->assertSame($expectedRefund, $result);
        $this->assertEquals(StateEnum::SUCCESSFUL, $result->state);
    }

    public function testSuccessfulPartialRefund(): void
    {
        $spaceId = 1;
        $transactionId = 123;

        $transaction = new Transaction();
        $transaction->id = $transactionId;
        $transaction->authorizedAmount = 100.00;

        $this->transactionService->method('getTransaction')
            ->willReturn($transaction);

        $context = new RefundContext(
            transactionId: $transactionId,
            amount: 20.00,
            merchantReference: 'ref-partial',
            type: TypeEnum::MERCHANT_INITIATED_ONLINE,
        );

        $expectedRefund = new Refund();
        $expectedRefund->id = 778;
        $expectedRefund->amount = 20.00;
        $expectedRefund->state = StateEnum::SUCCESSFUL;

        $this->gateway->expects($this->once())
            ->method('refund')
            ->with($spaceId, $context)
            ->willReturn($expectedRefund);

        $result = $this->service->createRefund($spaceId, $context);

        $this->assertEquals(20.00, $result->amount);
    }

    public function testGatewayFailure(): void
    {
        $spaceId = 1;
        $transactionId = 123;

        $transaction = new Transaction();
        $transaction->id = $transactionId;
        $transaction->authorizedAmount = 100.00;

        $this->transactionService->method('getTransaction')
            ->willReturn($transaction);

        $context = new RefundContext(
            transactionId: $transactionId,
            amount: 50.00,
            merchantReference: 'ref-fail',
            type: TypeEnum::MERCHANT_INITIATED_ONLINE,
        );

        $this->gateway->expects($this->once())
            ->method('refund')
            ->willThrowException(new \Exception("SDK Error"));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("SDK Error");

        $this->service->createRefund($spaceId, $context);
    }
    public function testValidateRefundItemTotalExceedsOriginal(): void
    {
        $spaceId = 1;
        $transactionId = 123;

        $itemA = new LineItem();
        $itemA->uniqueId = 'item-a';
        $itemA->quantity = 2;
        $itemA->amountIncludingTax = 50.00; // 25.00 each

        $transaction = new Transaction();
        $transaction->id = $transactionId;
        $transaction->authorizedAmount = 100.00;
        $transaction->lineItems = [$itemA];

        $this->transactionService->method('getTransaction')
            ->willReturn($transaction);

        $context = new RefundContext(
            transactionId: $transactionId,
            amount: 60.00,
            merchantReference: 'ref-1',
            type: TypeEnum::MERCHANT_INITIATED_ONLINE,
            lineItems: [
                ['uniqueId' => 'item-a', 'quantity' => 0, 'amount' => 30.00], // (0*25) + (2*30) = 60.00. Exceeds 50.00
            ],
        );

        $this->expectException(InvalidRefundException::class);
        $this->expectExceptionMessage("Refund amount 60.00 for item 'item-a' exceeds original item amount 50.00.");

        $this->service->createRefund($spaceId, $context);
    }
    public function testListRefunds(): void
    {
        $spaceId = 1;
        $transactionId = 123;

        $refundA = new Refund();
        $refundA->id = 1;
        $refundA->amount = 10.00;

        $refundB = new Refund();
        $refundB->id = 2;
        $refundB->amount = 20.00;

        $expectedRefunds = [$refundA, $refundB];

        $this->gateway->expects($this->once())
            ->method('findByTransaction')
            ->with($spaceId, $transactionId)
            ->willReturn($expectedRefunds);

        $result = $this->service->getRefunds($spaceId, $transactionId);

        $this->assertCount(2, $result);
        $this->assertSame($refundA, $result[0]);
        $this->assertSame($refundB, $result[1]);
    }

    public function testGetRefundableLineItemsFiltersCorrectly(): void
    {
        $product = new LineItem();
        $product->uniqueId = 'product-1';
        $product->type = LineItem::TYPE_PRODUCT;
        $product->amountIncludingTax = 100.00;

        $discount = new LineItem();
        $discount->uniqueId = 'discount-1';
        $discount->type = LineItem::TYPE_DISCOUNT;
        $discount->amountIncludingTax = -20.00;

        $freeGift = new LineItem();
        $freeGift->uniqueId = 'gift-1';
        $freeGift->type = LineItem::TYPE_PRODUCT; // Even if product, price is 0
        $freeGift->amountIncludingTax = 0.00;

        $transaction = new Transaction();
        $transaction->lineItems = [$product, $discount, $freeGift];

        $result = $this->service->getRefundableLineItems($transaction);

        $this->assertCount(1, $result);
        $this->assertSame($product, $result[0]);
    }

    public function testRefundFailsOnDiscountItem(): void
    {
        $spaceId = 1;
        $transactionId = 123;

        $discountItem = new LineItem();
        $discountItem->uniqueId = 'discount-1';
        $discountItem->type = LineItem::TYPE_DISCOUNT;
        $discountItem->amountIncludingTax = -10.00;
        $discountItem->quantity = 1;

        $transaction = new Transaction();
        $transaction->id = $transactionId;
        $transaction->authorizedAmount = 50.00;
        $transaction->lineItems = [$discountItem];

        $this->transactionService->method('getTransaction')->willReturn($transaction);

        $context = new RefundContext(
            transactionId: $transactionId,
            amount: 10.00,
            merchantReference: 'ref-fail',
            type: TypeEnum::MERCHANT_INITIATED_ONLINE,
            lineItems: [
                ['uniqueId' => 'discount-1', 'quantity' => 1, 'amount' => 10.00],
            ],
        );

        $this->expectException(InvalidRefundException::class);
        $this->expectExceptionMessage("Cannot refund line item 'discount-1'. Discounts cannot be refunded.");

        $this->service->createRefund($spaceId, $context);
    }

    public function testRefundFailsOnZeroAmountItem(): void
    {
        $spaceId = 1;
        $transactionId = 123;

        $freeItem = new LineItem();
        $freeItem->uniqueId = 'free-1';
        $freeItem->type = LineItem::TYPE_PRODUCT;
        $freeItem->amountIncludingTax = 0.00;
        $freeItem->quantity = 1;

        $transaction = new Transaction();
        $transaction->id = $transactionId;
        $transaction->authorizedAmount = 50.00;
        $transaction->lineItems = [$freeItem];

        $this->transactionService->method('getTransaction')->willReturn($transaction);

        $context = new RefundContext(
            transactionId: $transactionId,
            amount: 0.00,
            merchantReference: 'ref-fail',
            type: TypeEnum::MERCHANT_INITIATED_ONLINE,
            lineItems: [
                ['uniqueId' => 'free-1', 'quantity' => 1, 'amount' => 0.00],
            ],
        );

        $this->expectException(InvalidRefundException::class);
        $this->expectExceptionMessage("Cannot refund line item 'free-1'. Items with zero or negative amounts cannot be refunded.");

        $this->service->createRefund($spaceId, $context);
    }
}
