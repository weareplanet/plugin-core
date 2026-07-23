<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV2;

use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\TokenMapperTrait;
use WeArePlanet\PluginCore\Token\Exception\MissingTokenException;
use WeArePlanet\PluginCore\Token\Exception\TokenException;
use WeArePlanet\PluginCore\Token\Token;
use WeArePlanet\PluginCore\Token\TokenGatewayInterface;
use WeArePlanet\Sdk\Model\Token as SdkToken;
use WeArePlanet\Sdk\Service\TransactionsService as SdkTransactionsService;

/**
 * SDK implementation of the TokenGatewayInterface for API V2.
 */
#[LogContext(domain: 'transaction', subdomain: 'recurring')]
class TokenGateway implements TokenGatewayInterface
{
    use DomainLoggerTrait;
    use TokenMapperTrait;

    /**
     * @var SdkTransactionsService
     */
    private SdkTransactionsService $transactionsService;

    /**
     * Constructs the TokenGateway instance.
     *
     * @param SdkProvider $sdkProvider
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly SdkProvider $sdkProvider,
        LoggerInterface $logger,
    ) {
        $this->initializeLogger($logger);
        $this->transactionsService = $this->sdkProvider->getService(SdkTransactionsService::class);
    }

    /**
     * Attempts to retrieve or map a token for a given transaction.
     *
     * Enforces fail-fast behavior: if the transaction does not have an associated token,
     * it throws MissingTokenException.
     *
     * @param int $spaceId
     * @param int $transactionId
     * @return Token
     * @throws MissingTokenException
     * @throws TokenException
     */
    public function createToken(int $spaceId, int $transactionId): Token
    {
        $this->logger->debug(
            'Creating/Fetching Token for Transaction {transactionId} in Space {spaceId} (V2).',
            [
                'spaceId' => $spaceId,
                'transactionId' => $transactionId,
            ],
        );

        try {
            $transaction = $this->transactionsService->getPaymentTransactionsId($transactionId, $spaceId, ['token']);
            $sdkToken = $transaction->getToken();

            if ($sdkToken === null) {
                $this->logger->error(
                    'Token creation failed: Transaction does not have an associated token. The original transaction must have been created with tokenizationMode = FORCE_CREATION.',
                    [
                        'spaceId' => $spaceId,
                        'transactionId' => $transactionId,
                    ],
                );
                throw new MissingTokenException(
                    "Transaction {$transactionId} in Space {$spaceId} has no associated token.",
                    new LocalizedString('The transaction has no associated token.'),
                );
            }

            return $this->mapToToken($sdkToken, $spaceId);
        } catch (\Throwable $e) {
            if (!($e instanceof MissingTokenException)) {
                $this->logger->error(
                    'Failed to fetch token for transaction: {errorMessage}',
                    [
                        'errorMessage' => $e->getMessage(),
                        'exception' => $e,
                        'spaceId' => $spaceId,
                        'transactionId' => $transactionId,
                    ],
                );
                throw new TokenException(
                    "Failed to fetch token for transaction {$transactionId}: " . $e->getMessage(),
                    new LocalizedString('Token retrieval failed. Please try again or contact support.'),
                    $e,
                );
            }
            throw $e;
        }
    }
}
