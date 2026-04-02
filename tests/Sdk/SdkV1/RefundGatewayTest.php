<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\SdkV1;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Refund\Refund;
use WeArePlanet\PluginCore\Refund\RefundContext;
use WeArePlanet\PluginCore\Refund\Type as RefundType;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\SdkV1\RefundGateway;
use WeArePlanet\PluginCore\Transaction\Transaction;
use WeArePlanet\Sdk\Model\Refund as SdkRefund;
use WeArePlanet\Sdk\Model\RefundCreate as SdkRefundCreate;
use WeArePlanet\Sdk\Model\RefundState;
use WeArePlanet\Sdk\Service\RefundService as SdkRefundService;

class RefundGatewayTest extends TestCase
{
    private RefundGateway $gateway;
    private MockObject|LoggerInterface $logger;
    private MockObject|SdkRefundService $refundService;
    private MockObject|SdkProvider $sdkProvider;

    protected function setUp(): void
    {
        $this->sdkProvider = $this->createMock(SdkProvider::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->refundService = $this->createMock(SdkRefundService::class);

        $this->sdkProvider->method('getService')
            ->with(SdkRefundService::class)
            ->willReturn($this->refundService);

        $this->gateway = new RefundGateway($this->sdkProvider, $this->logger);
    }

    public function testFindByTransactionReturnsArrayOfRefunds(): void
    {
        $spaceId = 1;
        $transactionId = 2;

        $sdkRefund = new SdkRefund();
        $sdkRefund->setId(10);
        $sdkRefund->setAmount(50.0);
        $sdkRefund->setExternalId('ext-1');
        $sdkRefund->setState(RefundState::SUCCESSFUL);

        $this->refundService->expects($this->once())
            ->method('search')
            ->willReturn([$sdkRefund]);

        $results = $this->gateway->findByTransaction($spaceId, $transactionId);

        $this->assertCount(1, $results);
        $result = $results[0];
        $this->assertEquals(10, $result->id);
        $this->assertEquals(50.0, $result->amount);
        $this->assertEquals('SUCCESSFUL', $result->state->value);
    }

    public function testRefundDelegatesToServiceAndMapsResult(): void
    {
        $spaceId = 1;
        $transaction = new Transaction();
        $transaction->id = 2;

        $context = new RefundContext(
            $transaction->id,
            10.0,
            'ref-1',
            RefundType::MERCHANT_INITIATED_ONLINE,
            [],
        );

        $sdkRefund = new SdkRefund();
        $sdkRefund->setId(20);
        $sdkRefund->setAmount(10.0);
        $sdkRefund->setExternalId('ext-2');
        $sdkRefund->setState(RefundState::PENDING);

        $this->refundService->expects($this->once())
            ->method('refund')
            ->with($this->equalTo($spaceId), $this->callback(function (SdkRefundCreate $create) use ($context) {
                return $create->getTransaction() === $context->transactionId &&
                    $create->getAmount() === $context->amount &&
                    $create->getMerchantReference() === $context->merchantReference;
            }))
            ->willReturn($sdkRefund);

        $result = $this->gateway->refund($spaceId, $context);

        $this->assertInstanceOf(Refund::class, $result);
        $this->assertEquals(20, $result->id);
        $this->assertEquals(10.0, $result->amount);
    }
}
