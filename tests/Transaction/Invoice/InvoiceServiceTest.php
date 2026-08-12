<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Transaction\Invoice;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Transaction\Invoice\Invoice;
use WeArePlanet\PluginCore\Transaction\Invoice\InvoiceCollection;
use WeArePlanet\PluginCore\Transaction\Invoice\InvoiceGatewayInterface;
use WeArePlanet\PluginCore\Transaction\Invoice\InvoiceSearchCriteria;
use WeArePlanet\PluginCore\Transaction\Invoice\InvoiceService;

class InvoiceServiceTest extends TestCase
{
    private const INVOICE_ID = 999;
    private const SPACE_ID = 42;

    private MockObject|InvoiceGatewayInterface $gateway;
    private InvoiceService $service;

    private function makeInvoice(): Invoice
    {
        $invoice = new Invoice();
        $invoice->id = self::INVOICE_ID;
        $invoice->amount = 25.0;

        return $invoice;
    }

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(InvoiceGatewayInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new InvoiceService($this->gateway, $logger);
    }

    public function testFindDelegatesToGateway(): void
    {
        $invoice = $this->makeInvoice();

        $this->gateway->expects($this->once())
            ->method('find')
            ->with(self::SPACE_ID, self::INVOICE_ID)
            ->willReturn($invoice);

        $this->assertSame($invoice, $this->service->find(self::SPACE_ID, self::INVOICE_ID));
    }

    public function testFindReturnsNullWhenTheInvoiceDoesNotExist(): void
    {
        $this->gateway->expects($this->once())
            ->method('find')
            ->with(self::SPACE_ID, self::INVOICE_ID)
            ->willReturn(null);

        $this->assertNull($this->service->find(self::SPACE_ID, self::INVOICE_ID));
    }

    public function testGetDelegatesToGateway(): void
    {
        $invoice = $this->makeInvoice();

        $this->gateway->expects($this->once())
            ->method('get')
            ->with(self::SPACE_ID, self::INVOICE_ID)
            ->willReturn($invoice);

        $this->assertSame($invoice, $this->service->get(self::SPACE_ID, self::INVOICE_ID));
    }

    public function testSearchPassesCriteriaThroughUnchanged(): void
    {
        $criteria = new InvoiceSearchCriteria(limit: 1, filters: ['transaction.id' => 1234]);
        $collection = new InvoiceCollection($this->makeInvoice());

        $this->gateway->expects($this->once())
            ->method('search')
            ->with(self::SPACE_ID, $this->identicalTo($criteria))
            ->willReturn($collection);

        $this->assertSame($collection, $this->service->search(self::SPACE_ID, $criteria));
    }

    public function testSearchReturnsEmptyCollectionWhenNothingMatches(): void
    {
        $criteria = new InvoiceSearchCriteria(limit: 1);

        $this->gateway->expects($this->once())
            ->method('search')
            ->willReturn(new InvoiceCollection());

        $this->assertNull($this->service->search(self::SPACE_ID, $criteria)->first());
    }
}
