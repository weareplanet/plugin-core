<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\SdkV2;

use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Transaction\TransactionComment;
use WeArePlanet\PluginCore\Transaction\TransactionCommentGatewayInterface;
use WeArePlanet\Sdk\Model\TransactionComment as SdkTransactionComment;
use WeArePlanet\Sdk\Service\TransactionCommentsService as SdkTransactionCommentService;

class TransactionCommentGateway implements TransactionCommentGatewayInterface
{
    private SdkTransactionCommentService $service;

    public function __construct(
        private readonly SdkProvider $sdkProvider,
        private readonly LoggerInterface $logger,
    ) {
        $this->service = $this->sdkProvider->getService(SdkTransactionCommentService::class);
    }

    /**
     * @inheritDoc
     */
    public function getComments(int $spaceId, int $transactionId): array
    {
        try {
            $this->logger->debug("Fetching comments for Transaction $transactionId in Space $spaceId (V2).");
            // V2: getPaymentTransactionsTransactionIdComments
            // Arguments: $transaction_id, $space
            $sdkComments = $this->service->getPaymentTransactionsTransactionIdComments($transactionId, $spaceId);
            $items = (is_object($sdkComments) && method_exists($sdkComments, 'getData')) ? $sdkComments->getData() : (array)$sdkComments;

            return array_map([$this, 'mapToTransactionComment'], $items);
        } catch (\Exception $e) {
            $this->logger->error("Failed to fetch transaction comments: " . $e->getMessage());
            return [];
        }
    }

    private function mapToTransactionComment(SdkTransactionComment $sdkComment): TransactionComment
    {
        $comment = new TransactionComment();
        $comment->id = $sdkComment->getId();
        $comment->content = $sdkComment->getContent();

        $createdOn = $sdkComment->getCreatedOn();
        if ($createdOn) {
            $comment->createdOn = \DateTimeImmutable::createFromMutable($createdOn);
        }

        return $comment;
    }
}
