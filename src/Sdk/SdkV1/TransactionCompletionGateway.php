<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\SdkV1;

use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Transaction\Completion\State as StateEnum;
use WeArePlanet\PluginCore\Transaction\Completion\TransactionCompletion;
use WeArePlanet\PluginCore\Transaction\Completion\TransactionCompletionGatewayInterface;
use WeArePlanet\Sdk\Model\TransactionCompletion as SdkTransactionCompletion;
use WeArePlanet\Sdk\Service\TransactionCompletionService as SdkTransactionCompletionService;
use WeArePlanet\Sdk\Service\TransactionVoidService as SdkTransactionVoidService;

/**
 * SDK v1 implementation of the transaction completion gateway.
 *
 * This class interacts with the WeArePlanet SDK to perform capture operations
 * and maps SDK objects to domain entities.
 */
class TransactionCompletionGateway implements TransactionCompletionGatewayInterface
{
    public function __construct(
        private readonly SdkProvider $sdkProvider,
    ) {
    }

    /**
     * Captures an authorized transaction by creating a completion online.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID to capture.
     * @return TransactionCompletion The resulting completion domain object.
     */
    public function capture(int $spaceId, int $transactionId): TransactionCompletion
    {
        /** @var SdkTransactionCompletionService $service */
        $service = $this->sdkProvider->getService(SdkTransactionCompletionService::class);

        // Call the SDK to create the completion online (immediate capture)
        $sdkResult = $service->completeOnline($spaceId, $transactionId);

        // Map the SDK result to our domain entity
        return $this->mapToTransactionCompletion($sdkResult);
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

        if ($sdkCompletion->getLineItems()) {
            $completion->lineItems = array_map(function ($sdkItem) {
                $item = new \WeArePlanet\PluginCore\LineItem\LineItem();
                $item->uniqueId = $sdkItem->getUniqueId();
                $item->sku = $sdkItem->getSku();
                $item->name = $sdkItem->getName();
                $item->quantity = $sdkItem->getQuantity();
                $item->amountIncludingTax = $sdkItem->getAmountIncludingTax();
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
     * @return string The state of the void operation.
     */
    public function void(int $spaceId, int $transactionId): string
    {
        /** @var SdkTransactionVoidService $service */
        $service = $this->sdkProvider->getService(SdkTransactionVoidService::class);

        $void = $service->voidOnline($spaceId, $transactionId);

        return (string)$void->getState();
    }
}
