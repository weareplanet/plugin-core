<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\WebServiceAPIV1;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\InvoiceGateway;
use WeArePlanet\PluginCore\Transaction\Invoice\Exception\InvoiceException;
use WeArePlanet\Sdk\ApiException;
use WeArePlanet\Sdk\Model\LineItem as SdkLineItem;
use WeArePlanet\Sdk\Model\TransactionInvoice as SdkTransactionInvoice;
use WeArePlanet\Sdk\Service\TransactionInvoiceService as SdkTransactionInvoiceService;

class InvoiceGatewayTest extends TestCase
{
    private InvoiceGateway $gateway;
    private MockObject|SdkTransactionInvoiceService $invoiceService;

    private function sdkInvoice(int $id): SdkTransactionInvoice
    {
        $item = new SdkLineItem();
        $item->setUniqueId('product-1');
        $item->setSku('product-1');
        $item->setName('Product 1');
        $item->setQuantity(1.0);
        $item->setAmountIncludingTax(40.0);
        $item->setUnitPriceIncludingTax(40.0);

        $invoice = new SdkTransactionInvoice();
        $invoice->setId($id);
        $invoice->setLineItems([$item]);

        return $invoice;
    }

    protected function setUp(): void
    {
        $sdkProvider = $this->createMock(SdkProvider::class);
        $this->invoiceService = $this->createMock(SdkTransactionInvoiceService::class);
        $sdkProvider->method('getService')
            ->with(SdkTransactionInvoiceService::class)
            ->willReturn($this->invoiceService);

        $this->gateway = new InvoiceGateway($sdkProvider, $this->createMock(LoggerInterface::class));
    }

    public function testFindMapsAnExistingInvoice(): void
    {
        $this->invoiceService->method('read')->with(1, 7)->willReturn($this->sdkInvoice(7));

        $invoice = $this->gateway->find(1, 7);

        $this->assertSame(7, $invoice->id);
        $this->assertCount(1, $invoice->lineItems);
    }

    public function testFindReturnsNullOnNotFound(): void
    {
        $this->invoiceService->method('read')->willThrowException(new ApiException('Not Found', 404));

        $this->assertNull($this->gateway->find(1, 999));
    }

    /**
     * The V1 API answers a read of an unknown ID with an empty model, not a 404.
     */
    public function testFindReturnsNullWhenTheApiReturnsAnEmptyModel(): void
    {
        $this->invoiceService->method('read')->with(1, 999)->willReturn(new SdkTransactionInvoice());

        $this->assertNull($this->gateway->find(1, 999));
    }

    public function testGetMapsAnExistingInvoice(): void
    {
        $this->invoiceService->method('read')->with(1, 7)->willReturn($this->sdkInvoice(7));

        $this->assertSame(7, $this->gateway->get(1, 7)->id);
    }

    public function testGetThrowsWhenTheApiReturnsAnEmptyModel(): void
    {
        $this->invoiceService->method('read')->with(1, 999)->willReturn(new SdkTransactionInvoice());

        $this->expectException(InvoiceException::class);
        $this->expectExceptionMessage('Transaction invoice 999 not found in space 1.');

        $this->gateway->get(1, 999);
    }
}
