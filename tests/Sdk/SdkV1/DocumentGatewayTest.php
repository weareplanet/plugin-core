<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\SdkV1;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Document\RenderedDocument;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\SdkV1\DocumentGateway;
use WeArePlanet\Sdk\Model\RenderedDocument as SdkRenderedDocument;
use WeArePlanet\Sdk\Model\TransactionInvoice as SdkTransactionInvoice;
use WeArePlanet\Sdk\Service\RefundService as SdkRefundService;
use WeArePlanet\Sdk\Service\TransactionInvoiceService as SdkTransactionInvoiceService;
use WeArePlanet\Sdk\Service\TransactionService as SdkTransactionService;

class DocumentGatewayTest extends TestCase
{
    private DocumentGateway $gateway;
    private MockObject|SdkTransactionInvoiceService $invoiceService;
    private MockObject|LoggerInterface $logger;
    private MockObject|SdkRefundService $refundService;
    private MockObject|SdkProvider $sdkProvider;
    private MockObject|SdkTransactionService $transactionService;

    protected function setUp(): void
    {
        $this->sdkProvider = $this->createMock(SdkProvider::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->invoiceService = $this->createMock(SdkTransactionInvoiceService::class);
        $this->transactionService = $this->createMock(SdkTransactionService::class);
        $this->refundService = $this->createMock(SdkRefundService::class);

        $this->sdkProvider->method('getService')->willReturnMap([
            [SdkTransactionInvoiceService::class, $this->invoiceService],
            [SdkTransactionService::class, $this->transactionService],
            [SdkRefundService::class, $this->refundService],
        ]);

        $this->gateway = new DocumentGateway($this->sdkProvider, $this->logger);
    }

    public function testGetInvoiceReturnsRenderedDocument(): void
    {
        $spaceId = 1;
        $transactionId = 2;
        $invoiceId = 3;

        $sdkInvoice = new SdkTransactionInvoice();
        $sdkInvoice->setId($invoiceId);

        $this->invoiceService->expects($this->once())
            ->method('search')
            ->willReturn([$sdkInvoice]);

        $sdkDocument = new SdkRenderedDocument();
        $sdkDocument->setTitle('Invoice 123');
        $sdkDocument->setMimeType('application/pdf');
        $sdkDocument->setData(base64_encode('pdf-content'));

        $this->invoiceService->expects($this->once())
            ->method('getInvoiceDocument')
            ->with($spaceId, $invoiceId)
            ->willReturn($sdkDocument);

        $result = $this->gateway->getInvoice($spaceId, $transactionId);

        $this->assertInstanceOf(RenderedDocument::class, $result);
        $this->assertEquals('Invoice 123', $result->title);
        $this->assertEquals('application/pdf', $result->mimeType);
        $this->assertEquals('pdf-content', $result->data);
    }

    public function testGetInvoiceThrowsExceptionIfNotFound(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("No invoice found for transaction 2");

        $this->invoiceService->expects($this->once())
            ->method('search')
            ->willReturn([]);

        $this->gateway->getInvoice(1, 2);
    }

    public function testGetPackingSlipReturnsRenderedDocument(): void
    {
        $spaceId = 1;
        $transactionId = 2;

        $sdkDocument = new SdkRenderedDocument();
        $sdkDocument->setTitle('Packing Slip');
        $sdkDocument->setMimeType('application/pdf');
        $sdkDocument->setData(base64_encode('packing-slip-content'));

        $this->transactionService->expects($this->once())
            ->method('getPackingSlip')
            ->with($spaceId, $transactionId)
            ->willReturn($sdkDocument);

        $result = $this->gateway->getPackingSlip($spaceId, $transactionId);

        $this->assertInstanceOf(RenderedDocument::class, $result);
        $this->assertEquals('Packing Slip', $result->title);
        $this->assertEquals('packing-slip-content', $result->data);
    }

    public function testGetRefundCreditNoteReturnsRenderedDocument(): void
    {
        $spaceId = 1;
        $refundId = 5;

        $sdkDocument = new SdkRenderedDocument();
        $sdkDocument->setTitle('Credit Note');
        $sdkDocument->setMimeType('application/pdf');
        $sdkDocument->setData(base64_encode('credit-note-content'));

        $this->refundService->expects($this->once())
            ->method('getRefundDocument')
            ->with($spaceId, $refundId)
            ->willReturn($sdkDocument);

        $result = $this->gateway->getRefundCreditNote($spaceId, $refundId);

        $this->assertInstanceOf(RenderedDocument::class, $result);
        $this->assertEquals('Credit Note', $result->title);
        $this->assertEquals('credit-note-content', $result->data);
    }
}
