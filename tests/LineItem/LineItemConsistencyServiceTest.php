<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\LineItem;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\LineItem\Exception\LineItemConsistencyException;
use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\LineItem\LineItemConsistencyService;
use WeArePlanet\PluginCore\LineItem\RoundingStrategy;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\PluginCore\Settings\SettingsProviderInterface;

class LineItemConsistencyServiceTest extends TestCase
{
    private function createService(
        bool $enabled = true,
        RoundingStrategy $strategy = RoundingStrategy::BY_LINE_ITEM,
        ?LoggerInterface $logger = null,
    ): LineItemConsistencyService {
        $provider = $this->createMock(SettingsProviderInterface::class);
        $provider->method('getLineItemConsistencyEnabled')->willReturn($enabled);
        $provider->method('getLineItemRoundingStrategy')->willReturn($strategy);

        $settings = new Settings($provider);

        $logger = $logger ?? $this->createMock(LoggerInterface::class);

        return new LineItemConsistencyService($settings, $logger);
    }

    public function testDisabledConsistencyThrowsExceptionOnMismatch(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains("Line item discrepancy detected but consistency enforcement is DISABLED."),
                [
                    'domain' => 'transaction',
                    'subdomain' => 'checkout',
                    'source' => 'core',
                    'expectedAmount' => 10.00,
                    'calculatedAmount' => 9.99,
                    'difference' => 0.01,
                    'spaceId' => null,
                    'transactionId' => null,
                ],
            );

        $service = $this->createService(
            false,
            RoundingStrategy::BY_LINE_ITEM,
            $logger,
        );

        $item = new LineItem();
        $item->amountIncludingTax = 9.99;

        $this->expectException(LineItemConsistencyException::class);

        $service->ensureConsistency([$item], 10.00, 'CHF');
    }

    public function testLargeDiscrepancyThrowsException(): void
    {
        $service = $this->createService();

        $item = new LineItem();
        $item->amountIncludingTax = 5.00;

        $this->expectException(LineItemConsistencyException::class);

        $this->expectExceptionMessage('exceeds safety threshold');

        $service->ensureConsistency([$item], 10.00, 'CHF');
    }

    public function testNegativeAdjustment(): void
    {
        $service = $this->createService();

        $item = new LineItem();
        $item->amountIncludingTax = 10.02;

        // Expected 10.00, but item is 10.02 (Difference: -0.02)
        $result = $service->ensureConsistency([$item], 10.00, 'CHF');

        $this->assertCount(2, $result);
        $items = $result->all();
        $adjustment = end($items);
        $this->assertEquals(-0.02, $adjustment->amountIncludingTax);
        $this->assertEquals(LineItem::TYPE_DISCOUNT, $adjustment->type);
    }
    public function testPerfectMatchNeedsNoAdjustment(): void
    {
        $service = $this->createService();

        $item = new LineItem();
        $item->uniqueId = '1';
        $item->sku = 'SKU1';
        $item->name = 'Product';
        $item->quantity = 1;
        $item->amountIncludingTax = 10.00;

        $result = $service->ensureConsistency([$item], 10.00, 'CHF');

        $this->assertCount(1, $result);
    }

    public function testSanitizeNegativeLineItemsAdjustsDiscount(): void
    {
        $service = $this->createService();

        $item1 = new LineItem();
        $item1->amountIncludingTax = 100.00;
        $item1->type = LineItem::TYPE_PRODUCT;

        $item2 = new LineItem();
        $item2->amountIncludingTax = -150.00;
        $item2->type = LineItem::TYPE_DISCOUNT;

        $result = $service->sanitizeNegativeLineItems([$item1, $item2]);

        $this->assertEquals(100.00, $result->all()[0]->amountIncludingTax, );
        $this->assertEquals(-100.00, $result->all()[1]->amountIncludingTax, ); // Adjusted to -100
    }

    public function testSanitizeNegativeLineItemsAdjustsMultipleDiscounts(): void
    {
        $service = $this->createService();

        $item1 = new LineItem();
        $item1->amountIncludingTax = 100.00;
        $item1->type = LineItem::TYPE_PRODUCT;

        $item2 = new LineItem();
        $item2->amountIncludingTax = -100.00;
        $item2->type = LineItem::TYPE_DISCOUNT;

        $item3 = new LineItem();
        $item3->amountIncludingTax = -100.00;
        $item3->type = LineItem::TYPE_DISCOUNT;

        $result = $service->sanitizeNegativeLineItems([$item1, $item2, $item3]);

        $this->assertEquals(100.00, $result->all()[0]->amountIncludingTax, );
        // Factor = (-200 - (-100)) / -200 = -100 / -200 = 0.5
        // New amounts = -100 * 0.5 = -50
        $this->assertEquals(-50.00, $result->all()[1]->amountIncludingTax, );
        $this->assertEquals(-50.00, $result->all()[2]->amountIncludingTax, );
    }


    public function testSanitizeNegativeLineItemsNoChangeForPositiveSum(): void
    {
        $service = $this->createService();
        $item = new LineItem();
        $item->amountIncludingTax = 100.00;
        $item->type = LineItem::TYPE_PRODUCT;

        $result = $service->sanitizeNegativeLineItems([$item]);
        $this->assertEquals(100.00, $result->all()[0]->amountIncludingTax, );
    }

    public function testSanitizeNegativeLineItemsOnlyAdjustsDiscountType(): void
    {
        $service = $this->createService();

        $item1 = new LineItem();
        $item1->amountIncludingTax = 50.00;
        $item1->type = LineItem::TYPE_PRODUCT;

        $item2 = new LineItem();
        $item2->amountIncludingTax = -100.00;
        $item2->type = LineItem::TYPE_FEE; // Not a discount

        // Total = -50. No discount to heal.
        $result = $service->sanitizeNegativeLineItems([$item1, $item2]);

        $this->assertEquals(-100.00, $result->all()[1]->amountIncludingTax, ); // Should NOT be changed
    }

    public function testSanitizeNegativeLineItemsZeroesPureNegativeDiscount(): void
    {
        $service = $this->createService();

        $item1 = new LineItem();
        $item1->amountIncludingTax = -100.00;
        $item1->type = LineItem::TYPE_DISCOUNT;

        $result = $service->sanitizeNegativeLineItems([$item1]);

        $this->assertEquals(0.00, $result->all()[0]->amountIncludingTax, );
    }

    public function testSmallDiscrepancyAddsAdjustment(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains("Line item discrepancy detected; appending 'Rounding Adjustment' line item to satisfy gateway validation."),
                [
                    'domain' => 'transaction',
                    'subdomain' => 'checkout',
                    'source' => 'core',
                    'expectedAmount' => 10.00,
                    'calculatedAmount' => 9.98,
                    'difference' => 0.02,
                    'spaceId' => null,
                    'transactionId' => null,
                ],
            );

        $service = $this->createService(
            true,
            RoundingStrategy::BY_LINE_ITEM,
            $logger,
        );

        $item = new LineItem();
        $item->uniqueId = '1';
        $item->sku = 'SKU1';
        $item->name = 'Product';
        $item->quantity = 1;
        $item->amountIncludingTax = 9.98;

        // Expected 10.00, but item is 9.98 (Difference: 0.02)
        $result = $service->ensureConsistency([$item], 10.00, 'CHF');

        $this->assertCount(2, $result);

        $items = $result->all();
        $adjustment = end($items);
        $this->assertEquals('rounding-adjustment', $adjustment->sku);
        $this->assertEquals(0.02, $adjustment->amountIncludingTax);
        $this->assertEquals(LineItem::TYPE_FEE, $adjustment->type);
    }
}
