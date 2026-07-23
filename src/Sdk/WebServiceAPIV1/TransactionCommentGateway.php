<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV1;

use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\DateTimeMapperTrait;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Transaction\Exception\TransactionCommentException;
use WeArePlanet\PluginCore\Transaction\TransactionComment;
use WeArePlanet\PluginCore\Transaction\TransactionCommentCollection;
use WeArePlanet\PluginCore\Transaction\TransactionCommentGatewayInterface;
use WeArePlanet\Sdk\Model\TransactionComment as SdkTransactionComment;
use WeArePlanet\Sdk\Service\TransactionCommentService as SdkTransactionCommentService;

/**
 * Gateway for retrieving transaction comments.
 */
#[LogContext(domain: 'transaction')]
class TransactionCommentGateway implements TransactionCommentGatewayInterface
{
    use DateTimeMapperTrait;
    use DomainLoggerTrait;

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
        LoggerInterface $logger,
    ) {
        $this->initializeLogger($logger);
        $this->service = $this->sdkProvider->getService(SdkTransactionCommentService::class);
    }

    /**
     * @inheritDoc
     */
    public function getComments(int $spaceId, int $transactionId): TransactionCommentCollection
    {
        try {
            $this->logger->debug(
                'Fetching comments for Transaction {transactionId} in Space {spaceId}.',
                [
                    'spaceId' => $spaceId,
                    'transactionId' => $transactionId,
                ],
            );
            $sdkComments = $this->service->all($spaceId, $transactionId);

            return new TransactionCommentCollection(...array_map([$this, 'mapToTransactionComment'], $sdkComments));
        } catch (\Throwable $e) {
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
                new LocalizedString('An error occurred while fetching transaction comments.'),
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
