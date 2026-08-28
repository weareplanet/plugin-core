<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Sdk\WebServiceAPIV2;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Refund\Exception\RefundException;
use WeArePlanet\PluginCore\Refund\LineItem\RefundLineItemCollection;
use WeArePlanet\PluginCore\Refund\Refund;
use WeArePlanet\PluginCore\Refund\RefundContext;
use WeArePlanet\PluginCore\Refund\Type as RefundType;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\RefundGateway;
use WeArePlanet\PluginCore\Transaction\Transaction;
use WeArePlanet\Sdk\ApiException;
use WeArePlanet\Sdk\Model\FailureReason as SdkFailureReason;
use WeArePlanet\Sdk\Model\Refund as SdkRefund;
use WeArePlanet\Sdk\Model\RefundCreate as SdkRefundCreate;
use WeArePlanet\Sdk\Model\RefundSearchResponse as SdkRefundSearchResponse;
use WeArePlanet\Sdk\Model\RefundState as SdkRefundState;
use WeArePlanet\Sdk\Service\RefundsService as SdkRefundsService;

class RefundGatewayTest extends TestCase
{
    private RefundGateway $gateway;
    private MockObject|LoggerInterface $logger;
    private MockObject|SdkRefundsService $refundService;
    private MockObject|SdkProvider $sdkProvider;

    protected function setUp(): void
    {
        $this->sdkProvider = $this->createMock(SdkProvider::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->refundService = $this->createMock(SdkRefundsService::class);

        $this->sdkProvider->method('getService')
            ->with(SdkRefundsService::class)
            ->willReturn($this->refundService);

        $this->gateway = new RefundGateway(
            $this->sdkProvider,
            $this->logger,
        );
    }

    /**
     * Wraps refunds in the paged response shape the SDK actually returns.
     *
     * @param list<SdkRefund> $refunds
     */
    private function searchResponse(array $refunds, bool $hasMore = false): SdkRefundSearchResponse
    {
        $response = new SdkRefundSearchResponse();
        $response->setData($refunds);
        $response->setHasMore($hasMore);

        return $response;
    }

    public function testFindByTransactionReturnsArrayOfRefunds(): void
    {
        $spaceId = 1;
        $transactionId = 2;

        $sdkRefund = new SdkRefund();
        $sdkRefund->setId(10);
        $sdkRefund->setAmount(50.0);
        $sdkRefund->setExternalId('ext-1');
        $sdkRefund->setState(SdkRefundState::SUCCESSFUL);

        // V2: getPaymentRefundsSearch($space, filter, limit, offset, order, query)
        $this->refundService->expects($this->once())
            ->method('getPaymentRefundsSearch')
            ->with($spaceId, null, 100, 0, null, "transaction.id:$transactionId")
            ->willReturn($this->searchResponse([$sdkRefund]));

        $results = $this->gateway->findByTransaction($spaceId, $transactionId, );

        $this->assertCount(1, $results, );
        $result = $results->first();
        $this->assertEquals(10, $result->id, );
        $this->assertEquals(50.0, $result->amount, );
        $this->assertEquals('SUCCESSFUL', $result->state->value, );
    }

    /**
     * A transaction can carry more refunds than one page holds, so the gateway
     * must keep asking until the API reports no more.
     */
    public function testFindByTransactionPagesUntilTheApiReportsNoMore(): void
    {
        $spaceId = 1;
        $transactionId = 2;

        $makeRefund = static function (int $id): SdkRefund {
            $refund = new SdkRefund();
            $refund->setId($id);
            $refund->setAmount(10.0);
            $refund->setExternalId("ext-$id");
            $refund->setState(SdkRefundState::SUCCESSFUL);

            return $refund;
        };

        $firstPage = $this->searchResponse([$makeRefund(1), $makeRefund(2)], true);
        $secondPage = $this->searchResponse([$makeRefund(3)], false);

        $this->refundService->expects($this->exactly(2))
            ->method('getPaymentRefundsSearch')
            ->willReturnCallback(
                function (int $space, $expand, int $limit, int $offset, $order, string $query) use ($spaceId, $transactionId, $firstPage, $secondPage) {
                    self::assertSame($spaceId, $space);
                    self::assertSame(100, $limit);
                    self::assertSame("transaction.id:$transactionId", $query);

                    // The second page must resume after the two records already read.
                    return $offset === 0 ? $firstPage : $secondPage;
                },
            );

        $results = $this->gateway->findByTransaction($spaceId, $transactionId);

        $this->assertCount(3, $results);
        $this->assertSame([1, 2, 3], array_map(static fn (Refund $refund) => $refund->id, $results->all()));
    }

    public function testFindByTransactionThrowsRefundExceptionOnError(): void
    {
        $spaceId = 1;
        $transactionId = 2;

        $this->refundService->expects($this->once())
            ->method('getPaymentRefundsSearch')
            ->willThrowException(new \Exception('Search failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Failed to find refunds'));

        $this->expectException(RefundException::class);
        $this->gateway->findByTransaction($spaceId, $transactionId);
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
            new RefundLineItemCollection(),
        );

        $sdkRefund = new SdkRefund();
        $sdkRefund->setId(20);
        $sdkRefund->setAmount(10.0);
        $sdkRefund->setExternalId('ext-2');
        $sdkRefund->setState(SdkRefundState::PENDING);

        // V2: postPaymentRefunds($space, $create)
        $this->refundService->expects($this->once())
            ->method('postPaymentRefunds')
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

    /**
     * A caller-supplied externalId is the idempotency key the API uses to
     * recognise a retried refund instead of creating a duplicate, so it must
     * reach the SDK payload unchanged rather than being replaced by a
     * freshly generated one.
     */
    public function testRefundForwardsCallerSuppliedExternalId(): void
    {
        $spaceId = 1;
        $transaction = new Transaction();
        $transaction->id = 2;

        $context = new RefundContext(
            $transaction->id,
            10.0,
            'ref-1',
            RefundType::MERCHANT_INITIATED_ONLINE,
            new RefundLineItemCollection(),
            externalId: 'shop-retry-key-1',
        );

        $sdkRefund = new SdkRefund();
        $sdkRefund->setId(21);
        $sdkRefund->setAmount(10.0);
        $sdkRefund->setExternalId('shop-retry-key-1');
        $sdkRefund->setState(SdkRefundState::PENDING);

        $this->refundService->expects($this->once())
            ->method('postPaymentRefunds')
            ->with(
                $this->equalTo($spaceId),
                $this->callback(fn (SdkRefundCreate $create) => $create->getExternalId() === 'shop-retry-key-1'),
            )
            ->willReturn($sdkRefund);

        $this->gateway->refund($spaceId, $context);
    }

    /**
     * Without a caller-supplied externalId, a generated one must still be
     * sent: the SDK setter rejects null/empty, so omitting the field
     * entirely is not an option here.
     */
    public function testRefundGeneratesExternalIdWhenNotSupplied(): void
    {
        $spaceId = 1;
        $transaction = new Transaction();
        $transaction->id = 2;

        $context = new RefundContext(
            $transaction->id,
            10.0,
            'ref-1',
            RefundType::MERCHANT_INITIATED_ONLINE,
            new RefundLineItemCollection(),
        );

        $sdkRefund = new SdkRefund();
        $sdkRefund->setId(22);
        $sdkRefund->setAmount(10.0);
        $sdkRefund->setExternalId('generated');
        $sdkRefund->setState(SdkRefundState::PENDING);

        $this->refundService->expects($this->once())
            ->method('postPaymentRefunds')
            ->with(
                $this->equalTo($spaceId),
                $this->callback(fn (SdkRefundCreate $create) => is_string($create->getExternalId()) && $create->getExternalId() !== ''),
            )
            ->willReturn($sdkRefund);

        $this->gateway->refund($spaceId, $context);
    }

    public function testRefundMapsFailureReason(): void
    {
        $spaceId = 1;
        $transactionId = 2;

        $context = new RefundContext(
            $transactionId,
            10.0,
            'ref-fail',
            RefundType::MERCHANT_INITIATED_ONLINE,
            new RefundLineItemCollection(),
        );

        $failureReason = new SdkFailureReason();
        $failureReason->setDescription([
            'en-US' => 'Insufficient funds',
            'de-DE' => 'Unzureichende Deckung',
        ]);

        $sdkRefund = new SdkRefund();
        $sdkRefund->setId(40);
        $sdkRefund->setAmount(10.0);
        $sdkRefund->setExternalId('ext-fail');
        $sdkRefund->setState(SdkRefundState::FAILED);
        $sdkRefund->setFailureReason($failureReason);

        $this->refundService->expects($this->once())
            ->method('postPaymentRefunds')
            ->willReturn($sdkRefund);

        $result = $this->gateway->refund($spaceId, $context);

        $this->assertNotNull($result->failureReason);
        $this->assertSame('Insufficient funds', $result->failureReason->localize('en-US'));
        $this->assertSame('Unzureichende Deckung', $result->failureReason->localize('de-DE'));
    }

    public function testRefundFailedOnIsMapped(): void
    {
        $spaceId = 1;
        $transactionId = 2;

        $context = new RefundContext(
            $transactionId,
            10.0,
            'ref-dates',
            RefundType::MERCHANT_INITIATED_ONLINE,
            new RefundLineItemCollection(),
        );

        $createdOn = new \DateTime('2026-01-15T10:00:00+00:00');
        $failedOn = new \DateTime('2026-01-15T10:30:00+00:00');

        $sdkRefund = new SdkRefund();
        $sdkRefund->setId(41);
        $sdkRefund->setAmount(10.0);
        $sdkRefund->setExternalId('ext-dates');
        $sdkRefund->setState(SdkRefundState::FAILED);
        $sdkRefund->setCreatedOn($createdOn);
        $sdkRefund->setFailedOn($failedOn);

        $this->refundService->expects($this->once())
            ->method('postPaymentRefunds')
            ->willReturn($sdkRefund);

        $result = $this->gateway->refund($spaceId, $context);

        $this->assertInstanceOf(\DateTimeImmutable::class, $result->failedOn);
        $this->assertSame($failedOn->getTimestamp(), $result->failedOn->getTimestamp());
        $this->assertInstanceOf(\DateTimeImmutable::class, $result->createdOn);
        $this->assertSame($createdOn->getTimestamp(), $result->createdOn->getTimestamp());
    }

    public function testRefundMarksConnectionExceptionAsRetryable(): void
    {
        $context = new RefundContext(
            2,
            10.0,
            'ref-retry',
            RefundType::MERCHANT_INITIATED_ONLINE,
            new RefundLineItemCollection(),
        );

        $this->refundService->method('postPaymentRefunds')
            ->willThrowException(new ApiException('Connection failed', 0));

        try {
            $this->gateway->refund(1, $context);
            $this->fail('Expected a RefundException to be thrown.');
        } catch (RefundException $e) {
            $this->assertTrue($e->isRetryable());
        }
    }

    public function testRefundDoesNotMarkGenericFailureAsRetryable(): void
    {
        $context = new RefundContext(
            2,
            10.0,
            'ref-no-retry',
            RefundType::MERCHANT_INITIATED_ONLINE,
            new RefundLineItemCollection(),
        );

        $this->refundService->method('postPaymentRefunds')
            ->willThrowException(new \RuntimeException('Something else went wrong.'));

        try {
            $this->gateway->refund(1, $context);
            $this->fail('Expected a RefundException to be thrown.');
        } catch (RefundException $e) {
            $this->assertFalse($e->isRetryable());
        }
    }
}
