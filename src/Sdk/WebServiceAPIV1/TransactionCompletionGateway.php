<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV1;

use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\FailureReasonMapperTrait;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Transaction\Completion\CaptureRequest;
use WeArePlanet\PluginCore\Transaction\Completion\Exception\CompletionException;
use WeArePlanet\PluginCore\Transaction\Completion\State as StateEnum;
use WeArePlanet\PluginCore\Transaction\Completion\TransactionCompletion;
use WeArePlanet\PluginCore\Transaction\Completion\TransactionCompletionGatewayInterface;
use WeArePlanet\PluginCore\Transaction\Void\State as VoidStateEnum;
use WeArePlanet\PluginCore\Transaction\Void\TransactionVoid;
use WeArePlanet\Sdk\ApiException;
use WeArePlanet\Sdk\Model\CompletionLineItemCreate as SdkCompletionLineItemCreate;
use WeArePlanet\Sdk\Model\TransactionCompletion as SdkTransactionCompletion;
use WeArePlanet\Sdk\Model\TransactionCompletionRequest as SdkTransactionCompletionRequest;
use WeArePlanet\Sdk\Service\TransactionCompletionService as SdkTransactionCompletionService;
use WeArePlanet\Sdk\Service\TransactionVoidService as SdkTransactionVoidService;

/**
 * SDK v1 implementation of the transaction completion gateway.
 *
 * This class interacts with the WeArePlanet SDK to perform capture operations
 * and maps SDK objects to domain entities.
 */
#[LogContext(domain: 'transaction', subdomain: 'completion')]
class TransactionCompletionGateway implements TransactionCompletionGatewayInterface
{
    use DomainLoggerTrait;
    use FailureReasonMapperTrait;

    public function __construct(
        private readonly SdkProvider $sdkProvider,
        LoggerInterface $logger,
    ) {
        $this->initializeLogger($logger);
    }

    /**
     * Captures an authorized transaction by creating a completion, fully or partially.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID to capture.
     * @param CaptureRequest|null $request The capture details, or null for a full capture.
     * @return TransactionCompletion The resulting completion domain object.
     * @throws CompletionException If the capture fails.
     */
    public function capture(int $spaceId, int $transactionId, ?CaptureRequest $request = null): TransactionCompletion
    {
        $isPartial = $request !== null && !$request->lineItems->isEmpty();
        $this->logger->debug('Gateway: Capturing transaction.', [
            'transactionId' => $transactionId,
            'spaceId' => $spaceId,
            'partial' => $isPartial,
        ]);

        try {
            /** @var SdkTransactionCompletionService $service */
            $service = $this->sdkProvider->getService(SdkTransactionCompletionService::class);

            if (!$isPartial) {
                // No specific line items requested: capture the full remaining amount.
                $sdkResult = $service->completeOnline($spaceId, $transactionId);
            } else {
                $sdkCompletionRequest = new SdkTransactionCompletionRequest();
                $sdkCompletionRequest->setTransactionId($transactionId);
                $sdkCompletionRequest->setLastCompletion($request->isFinal);
                if ($request->externalId !== null) {
                    $sdkCompletionRequest->setExternalId($request->externalId);
                }
                if ($request->merchantReference !== null) {
                    $sdkCompletionRequest->setInvoiceMerchantReference($request->merchantReference);
                }
                $sdkCompletionRequest->setLineItems(array_map(
                    static function (LineItem $item): SdkCompletionLineItemCreate {
                        $item->sanitize();

                        $sdkItem = new SdkCompletionLineItemCreate();
                        $sdkItem->setUniqueId($item->uniqueId);
                        $sdkItem->setQuantity($item->quantity);
                        $sdkItem->setAmount($item->amountIncludingTax);
                        return $sdkItem;
                    },
                    iterator_to_array($request->lineItems),
                ));

                $sdkResult = $service->completePartiallyOnline($spaceId, $sdkCompletionRequest);
            }

            return $this->mapToTransactionCompletion($sdkResult);
        } catch (\Throwable $e) {
            $this->logger->error('Gateway: Failed to capture transaction.', [
                'exception' => $e,
                'transactionId' => $transactionId,
                'spaceId' => $spaceId,
            ]);
            throw new CompletionException(
                "Failed to capture transaction {$transactionId}: " . $e->getMessage(),
                new LocalizedString('An error occurred while capturing the transaction.'),
                $e,
            );
        }
    }

    /**
     * Finds a completion by ID.
     *
     * @param int $spaceId The space ID.
     * @param int $completionId The completion ID.
     * @return TransactionCompletion|null The completion, or null if not found.
     * @throws CompletionException If the API request fails (non-404).
     */
    public function find(int $spaceId, int $completionId): ?TransactionCompletion
    {
        try {
            /** @var SdkTransactionCompletionService $service */
            $service = $this->sdkProvider->getService(SdkTransactionCompletionService::class);

            $sdkCompletion = $service->read($spaceId, $completionId);

            // The V1 API returns an empty model instead of a 404 for unknown IDs.
            if ($sdkCompletion->getId() === null) {
                $this->logger->debug('Gateway: Completion not found.', [
                    'completionId' => $completionId,
                    'spaceId' => $spaceId,
                ]);
                return null;
            }

            return $this->mapToTransactionCompletion($sdkCompletion);
        } catch (\Throwable $e) {
            if ($e instanceof ApiException && $e->getCode() === 404) {
                $this->logger->debug('Gateway: Completion not found.', [
                    'completionId' => $completionId,
                    'spaceId' => $spaceId,
                ]);
                return null;
            }

            $this->logger->error('Gateway: Failed to find completion.', [
                'exception' => $e,
                'completionId' => $completionId,
                'spaceId' => $spaceId,
            ]);
            throw new CompletionException(
                "Failed to find completion {$completionId}: " . $e->getMessage(),
                new LocalizedString('An error occurred while retrieving the completion.'),
                $e,
            );
        }
    }

    /**
     * Gets a completion by ID and throws if not found or failed.
     *
     * @param int $spaceId The space ID.
     * @param int $completionId The completion ID.
     * @return TransactionCompletion The completion.
     * @throws CompletionException If the completion is not found or the request fails.
     */
    public function get(int $spaceId, int $completionId): TransactionCompletion
    {
        $this->logger->debug('Gateway: Reading completion.', [
            'completionId' => $completionId,
            'spaceId' => $spaceId,
        ]);

        try {
            /** @var SdkTransactionCompletionService $service */
            $service = $this->sdkProvider->getService(SdkTransactionCompletionService::class);

            $sdkCompletion = $service->read($spaceId, $completionId);

            // The V1 API returns an empty model instead of a 404 for unknown IDs.
            if ($sdkCompletion->getId() === null) {
                throw new CompletionException(
                    "Completion {$completionId} not found in space {$spaceId}.",
                    new LocalizedString('The completion could not be found.'),
                );
            }

            return $this->mapToTransactionCompletion($sdkCompletion);
        } catch (CompletionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('Gateway: Failed to read completion.', [
                'exception' => $e,
                'completionId' => $completionId,
                'spaceId' => $spaceId,
            ]);
            throw new CompletionException(
                "Failed to read completion {$completionId}: " . $e->getMessage(),
                new LocalizedString('An error occurred while retrieving the completion.'),
                $e,
            );
        }
    }

    /**
     * Maps an SDK TransactionCompletion to our domain TransactionCompletion.
     *
     * This ensures SDK objects do not leak into the domain layer.
     *
     * @param SdkTransactionCompletion $sdkCompletion The SDK completion object.
     * @return TransactionCompletion The domain completion object.
     */
    private function mapToTransactionCompletion(SdkTransactionCompletion $sdkCompletion): TransactionCompletion
    {
        $completion = new TransactionCompletion();

        $completion->id = $sdkCompletion->getId();
        $completion->linkedTransactionId = $sdkCompletion->getLinkedTransaction();
        $completion->state = StateEnum::from($sdkCompletion->getState());

        $reason = $sdkCompletion->getFailureReason();
        if ($reason !== null) {
            $completion->failureReason = $this->mapSdkFailureReason($reason);
        }

        if ($sdkCompletion->getLineItems()) {
            $completion->lineItems = array_map(function ($sdkItem) {
                $item = new \WeArePlanet\PluginCore\LineItem\LineItem();
                $item->uniqueId = $sdkItem->getUniqueId();
                $item->sku = $sdkItem->getSku();
                $item->name = $sdkItem->getName();
                $item->quantity = $sdkItem->getQuantity();
                $item->amountIncludingTax = $sdkItem->getAmountIncludingTax();
                $item->unitPriceIncludingTax = $sdkItem->getUnitPriceIncludingTax();
                $item->type = match ($sdkItem->getType()) {
                    \WeArePlanet\Sdk\Model\LineItemType::DISCOUNT => \WeArePlanet\PluginCore\LineItem\LineItem::TYPE_DISCOUNT,
                    \WeArePlanet\Sdk\Model\LineItemType::SHIPPING => \WeArePlanet\PluginCore\LineItem\LineItem::TYPE_SHIPPING,
                    \WeArePlanet\Sdk\Model\LineItemType::FEE => \WeArePlanet\PluginCore\LineItem\LineItem::TYPE_FEE,
                    default => \WeArePlanet\PluginCore\LineItem\LineItem::TYPE_PRODUCT,
                };
                return $item;
            }, $sdkCompletion->getLineItems());
        }

        return $completion;
    }
    /**
     * Voids an authorized transaction.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID to void.
     * @return TransactionVoid The resulting void domain object.
     * @throws CompletionException If the void fails.
     */
    public function void(int $spaceId, int $transactionId): TransactionVoid
    {
        try {
            /** @var SdkTransactionVoidService $service */
            $service = $this->sdkProvider->getService(SdkTransactionVoidService::class);

            $sdkVoid = $service->voidOnline($spaceId, $transactionId);

            $void = new TransactionVoid();
            $void->state = VoidStateEnum::from((string)$sdkVoid->getState());

            $reason = $sdkVoid->getFailureReason();
            if ($reason !== null) {
                $void->failureReason = $this->mapSdkFailureReason($reason);
            }

            return $void;
        } catch (\Throwable $e) {
            $this->logger->error('Gateway: Failed to void transaction.', [
                'exception' => $e,
                'transactionId' => $transactionId,
                'spaceId' => $spaceId,
            ]);
            throw new CompletionException(
                "Failed to void transaction {$transactionId}: " . $e->getMessage(),
                new LocalizedString('An error occurred while voiding the transaction.'),
                $e,
            );
        }
    }
}
