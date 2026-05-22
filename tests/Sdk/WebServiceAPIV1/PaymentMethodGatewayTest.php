<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\WebServiceAPIV1;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethod;
use WeArePlanet\PluginCore\PaymentMethod\State;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\PaymentMethodGateway;
use WeArePlanet\Sdk\Model\CreationEntityState;
use WeArePlanet\Sdk\Model\PaymentMethodConfiguration as SdkPaymentMethodConfiguration;
use WeArePlanet\Sdk\Service\PaymentMethodConfigurationService as SdkPaymentMethodConfigurationService;

class PaymentMethodGatewayTest extends TestCase
{
    private PaymentMethodGateway $gateway;
    private MockObject|LoggerInterface $logger;
    private MockObject|SdkProvider $sdkProvider;
    private MockObject|SdkPaymentMethodConfigurationService $service;

    protected function setUp(): void
    {
        $this->sdkProvider = $this->createMock(SdkProvider::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = $this->createMock(SdkPaymentMethodConfigurationService::class);

        $this->sdkProvider->method('getService')
            ->with(SdkPaymentMethodConfigurationService::class)
            ->willReturn($this->service);

        $this->gateway = new PaymentMethodGateway($this->sdkProvider, $this->logger);
    }

    public function testFetchByIdReturnsPaymentMethod(): void
    {
        $spaceId = 1;
        $id = 10;

        $sdkConfig = new SdkPaymentMethodConfiguration();
        $sdkConfig->setId($id);
        $sdkConfig->setLinkedSpaceId($spaceId);
        $sdkConfig->setState(CreationEntityState::ACTIVE);
        $sdkConfig->setResolvedTitle(['en-US' => 'Credit Card', 'de-DE' => 'Kreditkarte']);
        $sdkConfig->setResolvedDescription(['en-US' => 'Pay significantly later']);
        $sdkConfig->setSortOrder(5);
        $sdkConfig->setResolvedImageUrl('http://image.url');

        $this->service->expects($this->once())
            ->method('read')
            ->with($spaceId, $id)
            ->willReturn($sdkConfig);

        $result = $this->gateway->fetchById($spaceId, $id);

        $this->assertInstanceOf(PaymentMethod::class, $result);
        $this->assertEquals($id, $result->id);
        $this->assertEquals($spaceId, $result->spaceId);
        $this->assertEquals(State::ACTIVE, $result->state);
        $this->assertEquals('ACTIVE', $result->state->value);
        $this->assertEquals('Credit Card', $result->title->localize('en-US'));
        $this->assertEquals('Pay significantly later', $result->description->localize('en-US'));
        $this->assertEquals(5, $result->sortOrder);
        $this->assertEquals('http://image.url', $result->imageUrl);
    }

    public function testFetchByIdThrowsExceptionIfNotFound(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Payment method 10 not found.');

        $this->service->expects($this->once())
            ->method('read')
            ->willThrowException(new \Exception("Not found"));

        $this->gateway->fetchById(1, 10);
    }

    public function testFetchBySpaceIdReturnsArrayOfPaymentMethods(): void
    {
        $spaceId = 1;

        $sdkConfig1 = new SdkPaymentMethodConfiguration();
        $sdkConfig1->setId(11);
        $sdkConfig1->setLinkedSpaceId($spaceId);
        $sdkConfig1->setState(CreationEntityState::ACTIVE);
        $sdkConfig1->setResolvedTitle(['en-US' => 'Test Method']);
        $sdkConfig1->setSortOrder(1);

        $this->service->expects($this->once())
            ->method('search')
            ->with($spaceId, $this->anything()) // Helper specific to PHPUnit mock to match any argument
            ->willReturn([$sdkConfig1]);

        $results = $this->gateway->fetchBySpaceId($spaceId);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(PaymentMethod::class, $results[0]);
        $this->assertEquals(11, $results[0]->id);
    }
}
