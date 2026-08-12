<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Transaction\Completion;

use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\LineItem\LineItemCollection;
use WeArePlanet\PluginCore\Transaction\Completion\CaptureRequest;

class CaptureRequestTest extends TestCase
{
    public function testPartialCaptureRequiresAnExternalId(): void
    {
        $item = new LineItem();
        $item->uniqueId = 'sku-123';
        $item->quantity = 1.0;
        $item->amountIncludingTax = 25.00;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/idempotency key/');

        new CaptureRequest(lineItems: new LineItemCollection($item));
    }

    public function testPartialCaptureRejectsAnEmptyExternalId(): void
    {
        $item = new LineItem();
        $item->uniqueId = 'sku-123';
        $item->quantity = 1.0;
        $item->amountIncludingTax = 25.00;

        $this->expectException(\InvalidArgumentException::class);

        new CaptureRequest(lineItems: new LineItemCollection($item), externalId: '');
    }

    public function testPartialCaptureRejectsAnOverlongExternalId(): void
    {
        $item = new LineItem();
        $item->uniqueId = 'sku-123';
        $item->quantity = 1.0;
        $item->amountIncludingTax = 25.00;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at most 100 characters/');

        new CaptureRequest(lineItems: new LineItemCollection($item), externalId: str_repeat('x', 101));
    }

    public function testPartialCaptureAcceptsAValidExternalId(): void
    {
        $item = new LineItem();
        $item->uniqueId = 'sku-123';
        $item->quantity = 1.0;
        $item->amountIncludingTax = 25.00;

        $request = new CaptureRequest(
            lineItems: new LineItemCollection($item),
            isFinal: false,
            externalId: 'capture-123-shipment-1',
        );

        $this->assertSame('capture-123-shipment-1', $request->externalId);
        $this->assertFalse($request->isFinal);
    }

    public function testFullCaptureNeedsNoExternalId(): void
    {
        // A full capture takes a different endpoint that carries no external ID,
        // so an empty line item collection must stay constructible without one.
        $request = new CaptureRequest();

        $this->assertNull($request->externalId);
        $this->assertTrue($request->lineItems->isEmpty());
    }
}
