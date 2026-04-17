<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\WebServiceAPIV2;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethod;
use WeArePlanet\PluginCore\Transaction\Exception\TransactionException;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\PaymentMethodGateway;
use WeArePlanet\Sdk\Model\PaymentMethodConfiguration as SdkPaymentMethodConfiguration;
use WeArePlanet\Sdk\Model\CreationEntityState as SdkCreationEntityState;
use WeArePlanet\Sdk\Service\PaymentMethodConfigurationsService as SdkPaymentMethodConfigurationService;

class PaymentMethodGatewayTest extends TestCase
{
    private PaymentMethodGateway $gateway;
    private MockObject|SdkProvider $sdkProvider;
    private MockObject|LoggerInterface $logger;
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
        $sdkConfig->setState(SdkCreationEntityState::ACTIVE);
        $sdkConfig->setResolvedTitle(['en-US' => 'Credit Card', 'de-DE' => 'Kreditkarte']);
        $sdkConfig->setResolvedDescription(['en-US' => 'Pay significantly later']);
        $sdkConfig->setSortOrder(5);
        $sdkConfig->setResolvedImageUrl('http://image.url');

        // V2: getPaymentMethodConfigurationsId($id, $space)
        $this->service->expects($this->once())
            ->method('getPaymentMethodConfigurationsId')
            ->with($id, $spaceId)
            ->willReturn($sdkConfig);

        $result = $this->gateway->fetchById($spaceId, $id);

        $this->assertInstanceOf(PaymentMethod::class, $result);
        $this->assertEquals($id, $result->id);
        $this->assertEquals($spaceId, $result->spaceId);
        $this->assertEquals('ACTIVE', $result->state);
        $this->assertEquals('Credit Card', $result->name);
        $this->assertEquals('Pay significantly later', $result->description);
        $this->assertEquals(5, $result->sortOrder);
        $this->assertEquals('http://image.url', $result->imageUrl);
    }

    public function testFetchByIdThrowsExceptionIfNotFound(): void
    {
        $this->expectException(TransactionException::class);
        $this->expectExceptionMessage('Payment method 10 not found: Not found');

        $this->service->expects($this->once())
            ->method('getPaymentMethodConfigurationsId')
            ->willThrowException(new \Exception("Not found"));

        $this->gateway->fetchById(1, 10);
    }

    public function testFetchBySpaceIdReturnsArrayOfPaymentMethods(): void
    {
        $spaceId = 1;

        $sdkConfig1 = new SdkPaymentMethodConfiguration();
        $sdkConfig1->setId(11);
        $sdkConfig1->setLinkedSpaceId($spaceId);
        $sdkConfig1->setState(SdkCreationEntityState::ACTIVE);
        $sdkConfig1->setResolvedTitle(['en-US' => 'Test Method']);
        $sdkConfig1->setSortOrder(1);

        // V2 Search: getPaymentMethodConfigurationsSearch($space, $expand, $limit, $offset, $order, $query)
        $this->service->expects($this->once())
            ->method('getPaymentMethodConfigurationsSearch')
            ->with($spaceId, null, null, null, null, '-state:DELETED')
            ->willReturn([$sdkConfig1]);

        $results = $this->gateway->fetchBySpaceId($spaceId);

        $this->assertCount(1, $results);
        $this->assertInstanceOf(PaymentMethod::class, $results[0]);
        $this->assertEquals(11, $results[0]->id);
    }
}
