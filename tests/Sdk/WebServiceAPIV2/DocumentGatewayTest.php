<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\WebServiceAPIV2;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Document\RenderedDocument;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\DocumentGateway;
use WeArePlanet\Sdk\Model\RenderedDocument as SdkRenderedDocument;
use WeArePlanet\Sdk\Model\TransactionCompletion as SdkTransactionCompletion;
use WeArePlanet\Sdk\Model\TransactionInvoice as SdkTransactionInvoice;
use WeArePlanet\Sdk\Service\RefundsService as SdkRefundsService;
use WeArePlanet\Sdk\Service\TransactionCompletionsService as SdkTransactionCompletionsService;
use WeArePlanet\Sdk\Service\TransactionInvoicesService as SdkTransactionInvoicesService;
use WeArePlanet\Sdk\Service\TransactionsService as SdkTransactionsService;

class DocumentGatewayTest extends TestCase
{
    private DocumentGateway $gateway;
    private MockObject|SdkProvider $sdkProvider;
    private MockObject|LoggerInterface $logger;
    private MockObject|SdkTransactionInvoicesService $invoiceService;
    private MockObject|SdkTransactionsService $transactionService;
    private MockObject|SdkRefundsService $refundService;
    private MockObject|SdkTransactionCompletionsService $completionService;

    protected function setUp(): void
    {
        $this->sdkProvider = $this->createMock(SdkProvider::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->invoiceService = $this->createMock(SdkTransactionInvoicesService::class);
        $this->transactionService = $this->createMock(SdkTransactionsService::class);
        $this->refundService = $this->createMock(SdkRefundsService::class);
        $this->completionService = $this->createMock(SdkTransactionCompletionsService::class);

        $this->sdkProvider->method('getService')->willReturnMap([
            [SdkTransactionInvoicesService::class, $this->invoiceService],
            [SdkTransactionsService::class, $this->transactionService],
            [SdkRefundsService::class, $this->refundService],
            [SdkTransactionCompletionsService::class, $this->completionService],
        ]);

        $this->gateway = new DocumentGateway($this->sdkProvider, $this->logger);
    }

    public function testGetInvoiceReturnsRenderedDocument(): void
    {
        $spaceId = 1;
        $transactionId = 2;
        $invoiceId = 3;

        $sdkCompletion = $this->createMock(SdkTransactionCompletion::class);
        $sdkCompletion->method('getId')->willReturn(40);

        // 1. Completion Retrieval
        $this->completionService->expects($this->once())
            ->method('getPaymentTransactionsCompletionsSearch')
            ->with($spaceId, null, 1, null, null, "lineItemVersion.transaction.id:$transactionId")
            ->willReturn([$sdkCompletion]);

        $sdkInvoice = new SdkTransactionInvoice();
        $sdkInvoice->setId($invoiceId);

        // 2. Invoice Search: getPaymentTransactionsInvoicesSearch($space, filter, limit, offset, order, query)
        $this->invoiceService->expects($this->once())
            ->method('getPaymentTransactionsInvoicesSearch')
            ->with($spaceId, null, 1, null, null, "completion:40")
            ->willReturn([$sdkInvoice]);

        $sdkDocument = new SdkRenderedDocument();
        $sdkDocument->setTitle('Invoice 123');
        $sdkDocument->setMimeType('application/pdf');
        $sdkDocument->setData(base64_encode('pdf-content'));

        $this->invoiceService->expects($this->once())
            ->method('getPaymentTransactionsInvoicesIdDocument')
            ->with($invoiceId, $spaceId)
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

        // Fail at completion step
        $this->completionService->expects($this->once())
            ->method('getPaymentTransactionsCompletionsSearch')
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

        // V2: getPaymentTransactionsIdPackingSlipDocument
        $this->transactionService->expects($this->once())
            ->method('getPaymentTransactionsIdPackingSlipDocument')
            ->with($transactionId, $spaceId)
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

        // V2: getPaymentRefundsIdDocument
        $this->refundService->expects($this->once())
            ->method('getPaymentRefundsIdDocument')
            ->with($refundId, $spaceId)
            ->willReturn($sdkDocument);

        $result = $this->gateway->getRefundCreditNote($spaceId, $refundId);

        $this->assertInstanceOf(RenderedDocument::class, $result);
        $this->assertEquals('Credit Note', $result->title);
        $this->assertEquals('credit-note-content', $result->data);
    }
}
