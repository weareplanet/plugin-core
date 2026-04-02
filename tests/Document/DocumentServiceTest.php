<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Document;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Document\DocumentGatewayInterface;
use WeArePlanet\PluginCore\Document\DocumentService;
use WeArePlanet\PluginCore\Document\RenderedDocument;

class DocumentServiceTest extends TestCase
{
    private MockObject|DocumentGatewayInterface $gateway;
    private DocumentService $service;

    protected function setUp(): void
    {
        $this->gateway = $this->createMock(DocumentGatewayInterface::class);
        $this->service = new DocumentService($this->gateway);
    }

    public function testGetInvoice(): void
    {
        $spaceId = 1;
        $transactionId = 100;
        $document = new RenderedDocument('Invoice', 'application/pdf', 'dummy-data');

        $this->gateway->expects($this->once())
            ->method('getInvoice')
            ->with($spaceId, $transactionId)
            ->willReturn($document);

        $result = $this->service->getInvoice($spaceId, $transactionId);
        $this->assertSame($document, $result);
    }

    public function testGetPackingSlip(): void
    {
        $spaceId = 1;
        $transactionId = 100;
        $document = new RenderedDocument('Packing Slip', 'application/pdf', 'dummy-data');

        $this->gateway->expects($this->once())
            ->method('getPackingSlip')
            ->with($spaceId, $transactionId)
            ->willReturn($document);

        $result = $this->service->getPackingSlip($spaceId, $transactionId);
        $this->assertSame($document, $result);
    }

    public function testGetRefundDocument(): void
    {
        $spaceId = 1;
        $refundId = 200;
        $document = new RenderedDocument('Refund Credit Note', 'application/pdf', 'dummy-data');

        $this->gateway->expects($this->once())
            ->method('getRefundCreditNote')
            ->with($spaceId, $refundId)
            ->willReturn($document);

        $result = $this->service->getRefundDocument($spaceId, $refundId);
        $this->assertSame($document, $result);
    }

    public function testSdkExceptionHandling(): void
    {
        $spaceId = 1;
        $transactionId = 100;

        $this->gateway->expects($this->once())
            ->method('getInvoice')
            ->with($spaceId, $transactionId)
            ->willThrowException(new \Exception('SDK Error'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('SDK Error');

        $this->service->getInvoice($spaceId, $transactionId);
    }
}
