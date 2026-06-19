<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV2;

use WeArePlanet\PluginCore\Sdk\FailureReasonMapperTrait;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Transaction\Completion\State;
use WeArePlanet\PluginCore\Transaction\Completion\TransactionCompletion;
use WeArePlanet\PluginCore\Transaction\Completion\TransactionCompletionGatewayInterface;
use WeArePlanet\PluginCore\Transaction\Void\State as VoidState;
use WeArePlanet\PluginCore\Transaction\Void\TransactionVoid;
use WeArePlanet\Sdk\Model\TransactionCompletion as SdkTransactionCompletion;
use WeArePlanet\Sdk\Service\TransactionsService as SdkTransactionsService;

/**
 * SDK v2 implementation of the transaction completion gateway.
 *
 * This class interacts with the WeArePlanet SDK to perform capture and void operations.
 */
class TransactionCompletionGateway implements TransactionCompletionGatewayInterface
{
    use FailureReasonMapperTrait;

    private SdkTransactionsService $transactionsService;

    public function __construct(
        private readonly SdkProvider $sdkProvider,
    ) {
        $this->transactionsService = $this->sdkProvider->getService(SdkTransactionsService::class);
    }

    /**
     * Captures an authorized transaction by creating a completion online.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID to capture.
     * @return TransactionCompletion The resulting completion domain object.
     */
    public function capture(
        int $spaceId,
        int $transactionId,
    ): TransactionCompletion {
        // The SDK method returns the resulting completion object.
        $sdkResult = $this->transactionsService->postPaymentTransactionsIdCompleteOnline(
            $transactionId,
            $spaceId,
        );

        return $this->mapToTransactionCompletion($sdkResult);
    }

    /**
     * Maps an SDK TransactionCompletion to our domain TransactionCompletion.
     *
     * @param SdkTransactionCompletion $sdkCompletion The SDK completion object.
     * @return TransactionCompletion The domain completion object.
     */
    private function mapToTransactionCompletion(SdkTransactionCompletion $sdkCompletion): TransactionCompletion
    {
        $completion = new TransactionCompletion();

        $completion->id = $sdkCompletion->getId();
        $completion->linkedTransactionId = $sdkCompletion->getLinkedTransaction();
        if ($sdkCompletion->getState()) {
            $completion->state = State::from((string)$sdkCompletion->getState());
        }

        $completion->lineItems = $sdkCompletion->getLineItems() ?? [];

        $reason = $sdkCompletion->getFailureReason();
        if ($reason !== null) {
            $completion->failureReason = $this->mapSdkFailureReason($reason);
        }

        return $completion;
    }

    /**
     * Voids an authorized transaction.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID to void.
     * @return TransactionVoid The resulting void domain object.
     */
    public function void(
        int $spaceId,
        int $transactionId,
    ): TransactionVoid {
        // Voids the transaction using the SDK's online void method.
        $sdkVoid = $this->transactionsService->postPaymentTransactionsIdVoidOnline(
            $transactionId,
            $spaceId,
        );

        $void = new TransactionVoid();
        if ($sdkVoid->getState()) {
            $void->state = VoidState::from((string)$sdkVoid->getState());
        }

        $reason = $sdkVoid->getFailureReason();
        if ($reason !== null) {
            $void->failureReason = $this->mapSdkFailureReason($reason);
        }

        return $void;
    }
}
