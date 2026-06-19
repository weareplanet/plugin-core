<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV2;

use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\DateTimeMapperTrait;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Transaction\Exception\TransactionCommentException;
use WeArePlanet\PluginCore\Transaction\TransactionComment;
use WeArePlanet\PluginCore\Transaction\TransactionCommentGatewayInterface;
use WeArePlanet\Sdk\Model\TransactionComment as SdkTransactionComment;
use WeArePlanet\Sdk\Service\TransactionCommentsService as SdkTransactionCommentService;

/**
 * Gateway for retrieving transaction comments.
 */
class TransactionCommentGateway implements TransactionCommentGatewayInterface
{
    use DateTimeMapperTrait;

    /**
     * @var SdkTransactionCommentService
     */
    private SdkTransactionCommentService $service;

    /**
     * TransactionCommentGateway constructor.
     *
     * @param SdkProvider $sdkProvider
     * @param LoggerInterface $logger
     */
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
            $this->logger->debug(
                'Fetching comments for Transaction {transactionId} in Space {spaceId} (V2).',
                [
                    'spaceId' => $spaceId,
                    'transactionId' => $transactionId,
                ],
            );
            $sdkComments = $this->service->getPaymentTransactionsTransactionIdComments($transactionId, $spaceId);
            $items = (is_object($sdkComments) && method_exists($sdkComments, 'getData')) ? $sdkComments->getData() : (array)$sdkComments;

            return array_map([$this, 'mapToTransactionComment'], $items);
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to fetch transaction comments: {errorMessage}',
                [
                    'errorMessage' => $e->getMessage(),
                    'exception' => $e,
                    'spaceId' => $spaceId,
                    'transactionId' => $transactionId,
                ],
            );
            throw new TransactionCommentException(
                "Failed to fetch comments for transaction {$transactionId}: " . $e->getMessage(),
                new LocalizedString($e->getMessage()),
                0,
                $e,
            );
        }
    }

    /**
     * Maps SDK TransactionComment to Domain object.
     *
     * @param SdkTransactionComment $sdkComment
     * @return TransactionComment
     */
    private function mapToTransactionComment(SdkTransactionComment $sdkComment): TransactionComment
    {
        $comment = new TransactionComment();
        $comment->id = $sdkComment->getId();
        $comment->content = $sdkComment->getContent();
        $comment->createdOn = $this->toDateTimeImmutable($sdkComment->getCreatedOn());

        return $comment;
    }
}
