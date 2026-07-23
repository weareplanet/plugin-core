<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Refund;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\LineItem\LineItemCollection;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Refund\Exception\InvalidRefundException;
use WeArePlanet\PluginCore\Refund\LineItem\RefundLineItem;
use WeArePlanet\PluginCore\Refund\LineItem\RefundLineItemCollection;
use WeArePlanet\PluginCore\Refund\Refund;
use WeArePlanet\PluginCore\Refund\RefundCollection;
use WeArePlanet\PluginCore\Refund\RefundContext;
use WeArePlanet\PluginCore\Refund\RefundGatewayInterface;
use WeArePlanet\PluginCore\Refund\RefundService;
use WeArePlanet\PluginCore\Refund\State;
use WeArePlanet\PluginCore\Refund\Type;
use WeArePlanet\PluginCore\Transaction\Transaction;
use WeArePlanet\PluginCore\Transaction\TransactionService;

class RefundServiceTest extends TestCase
{
    private MockObject|RefundGatewayInterface $gateway;
    private MockObject|LoggerInterface $logger;
    private RefundService $service;
    private MockObject|TransactionService $transactionService;

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
            type: Type::MERCHANT_INITIATED_ONLINE,
        );

        $this->gateway->expects($this->once())
            ->method('refund')
            ->willThrowException(new \Exception("SDK Error"));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("SDK Error");

        $this->service->createRefund($spaceId, $context);
    }

    public function testGetRefundableLineItemsFallsBackToOriginalCart(): void
    {
        $spaceId = 1;
        $transactionId = 123;

        $product = new LineItem();
        $product->uniqueId = 'product-1';
        $product->amountIncludingTax = 100.00;

        // Discounts and zero-amount items must be filtered out of the fallback.
        $discount = new LineItem();
        $discount->uniqueId = 'discount-1';
        $discount->type = LineItem::TYPE_DISCOUNT;
        $discount->amountIncludingTax = -20.00;

        $freeGift = new LineItem();
        $freeGift->uniqueId = 'gift-1';
        $freeGift->amountIncludingTax = 0.00;

        $transaction = new Transaction();
        $transaction->id = $transactionId;
        $transaction->lineItems = [$product, $discount, $freeGift];

        // No successful refunds exist, so the original cart is refundable.
        $this->gateway->expects($this->once())
            ->method('findByTransaction')
            ->with($spaceId, $transactionId)
            ->willReturn(new RefundCollection());

        $this->transactionService->expects($this->once())
            ->method('getTransaction')
            ->with($spaceId, $transactionId)
            ->willReturn($transaction);

        $result = $this->service->getRefundableLineItems($spaceId, $transactionId);

        $this->assertCount(1, $result);
        $this->assertSame($product, $result->first());
    }

    public function testGetRefundableLineItemsReturnsLatestSuccessfulReducedState(): void
    {
        $spaceId = 1;
        $transactionId = 123;

        $older = new Refund();
        $older->id = 1;
        $older->state = State::SUCCESSFUL;
        $older->createdOn = new \DateTimeImmutable('2026-01-01T10:00:00Z');
        $older->reducedLineItems = new LineItemCollection();

        $remainingItem = new LineItem();
        $remainingItem->uniqueId = 'product-1';
        $remainingItem->amountIncludingTax = 50.00;

        // A discount in the reduced state must be filtered out client-side.
        $remainingDiscount = new LineItem();
        $remainingDiscount->uniqueId = 'discount-1';
        $remainingDiscount->type = LineItem::TYPE_DISCOUNT;
        $remainingDiscount->amountIncludingTax = -10.00;

        $latest = new Refund();
        $latest->id = 2;
        $latest->state = State::SUCCESSFUL;
        $latest->createdOn = new \DateTimeImmutable('2026-01-02T10:00:00Z');
        $latest->reducedLineItems = new LineItemCollection($remainingItem, $remainingDiscount);

        // A newer but FAILED refund must not win.
        $failed = new Refund();
        $failed->id = 3;
        $failed->state = State::FAILED;
        $failed->createdOn = new \DateTimeImmutable('2026-01-03T10:00:00Z');

        $this->gateway->expects($this->once())
            ->method('findByTransaction')
            ->with($spaceId, $transactionId)
            ->willReturn(new RefundCollection($older, $latest, $failed));

        // The original transaction must not be fetched when a successful refund exists.
        $this->transactionService->expects($this->never())
            ->method('getTransaction');

        $result = $this->service->getRefundableLineItems($spaceId, $transactionId);

        $this->assertCount(1, $result);
        $this->assertSame($remainingItem, $result->first());
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
        $expectedCollection = new RefundCollection(...$expectedRefunds, );

        $this->gateway->expects($this->once())
            ->method('findByTransaction')
            ->with($spaceId, $transactionId, )
            ->willReturn($expectedCollection);

        $result = $this->service->getRefunds($spaceId, $transactionId, );

        $this->assertCount(2, $result, );
        $this->assertSame($refundA, $result->all()[0], );
        $this->assertSame($refundB, $result->all()[1], );
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
            type: Type::MERCHANT_INITIATED_ONLINE,
            lineItems: new RefundLineItemCollection(
                new RefundLineItem('discount-1', 1, 10.00),
            ),
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
            type: Type::MERCHANT_INITIATED_ONLINE,
            lineItems: new RefundLineItemCollection(
                new RefundLineItem('free-1', 1, 0.00),
            ),
        );

        $this->expectException(InvalidRefundException::class);
        $this->expectExceptionMessage("Cannot refund line item 'free-1'. Items with zero or negative amounts cannot be refunded.");

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
            type: Type::MERCHANT_INITIATED_ONLINE,
        );

        $expectedRefund = new Refund();
        $expectedRefund->id = 777;
        $expectedRefund->state = State::SUCCESSFUL;

        $this->gateway->expects($this->once())
            ->method('refund')
            ->with($spaceId, $context)
            ->willReturn($expectedRefund);

        $result = $this->service->createRefund($spaceId, $context);

        $this->assertSame($expectedRefund, $result);
        $this->assertEquals(State::SUCCESSFUL, $result->state);
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
            type: Type::MERCHANT_INITIATED_ONLINE,
        );

        $expectedRefund = new Refund();
        $expectedRefund->id = 778;
        $expectedRefund->amount = 20.00;
        $expectedRefund->state = State::SUCCESSFUL;

        $this->gateway->expects($this->once())
            ->method('refund')
            ->with($spaceId, $context)
            ->willReturn($expectedRefund);

        $result = $this->service->createRefund($spaceId, $context);

        $this->assertEquals(20.00, $result->amount);
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
            type: Type::MERCHANT_INITIATED_ONLINE,
        );

        $this->expectException(InvalidRefundException::class);
        $this->expectExceptionMessage("Refund amount 150 exceeds the remaining authorized amount 100 for transaction 123.");

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
        $itemA->unitPriceIncludingTax = 25.00;

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
            type: Type::MERCHANT_INITIATED_ONLINE,
            lineItems: new RefundLineItemCollection(
                new RefundLineItem('item-a', 0, 30.00), // (0*25) + (2*30) = 60.00. Exceeds 50.00
            ),
        );

        $this->expectException(InvalidRefundException::class);
        $this->expectExceptionMessage("Refund amount 60.00 for item 'item-a' exceeds original item amount 50.00 in transaction 123.");

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
        $itemA->unitPriceIncludingTax = 50.00;

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
            type: Type::MERCHANT_INITIATED_ONLINE,
            lineItems: new RefundLineItemCollection(
                new RefundLineItem('item-a', 1, 60.00), // Exceeds 50.00
            ),
        );

        $this->expectException(InvalidRefundException::class);
        $this->expectExceptionMessage("Consistency Error: Total provided refund amount (60.00) does not match the sum of line item reductions (50.00) for transaction 123.");

        $this->service->createRefund($spaceId, $context);
    }
}
