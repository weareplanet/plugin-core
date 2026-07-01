<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV1;

use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\TokenMapperTrait;
use WeArePlanet\PluginCore\Token\Exception\MissingTokenException;
use WeArePlanet\PluginCore\Token\Exception\TokenException;
use WeArePlanet\PluginCore\Token\Token;
use WeArePlanet\PluginCore\Token\TokenGatewayInterface;
use WeArePlanet\Sdk\Service\TokenService as SdkTokenService;

/**
 * SDK implementation of the TokenGatewayInterface for API V1.
 */
class TokenGateway implements TokenGatewayInterface
{
    use TokenMapperTrait;

    /**
     * @var SdkTokenService
     */
    private SdkTokenService $tokenService;

    /**
     * Constructs the TokenGateway instance.
     *
     * @param SdkProvider $sdkProvider
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly SdkProvider $sdkProvider,
        private readonly LoggerInterface $logger,
    ) {
        $this->tokenService = $this->sdkProvider->getService(SdkTokenService::class);
    }

    /**
     * Attempts to create a token for a given transaction.
     *
     * Enforces fail-fast behavior: if the transaction does not support tokenization,
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
            'Attempting to create token for Transaction {transactionId} in Space {spaceId} (V1).',
            [
                'spaceId' => $spaceId,
                'transactionId' => $transactionId,
            ],
        );

        try {
            $sdkToken = $this->tokenService->createToken($spaceId, $transactionId);

            if ($sdkToken === null) {
                $this->logger->error(
                    'Token creation failed: SDK did not return a token. Transaction lacks the required tokenization state.',
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
        } catch (\Exception $e) {
            if (!($e instanceof MissingTokenException)) {
                $this->logger->error(
                    'Failed to create token for transaction: {errorMessage}',
                    [
                        'errorMessage' => $e->getMessage(),
                        'exception' => $e,
                        'spaceId' => $spaceId,
                        'transactionId' => $transactionId,
                    ],
                );
                throw new TokenException(
                    "Failed to create token for transaction {$transactionId}: " . $e->getMessage(),
                    new LocalizedString('Token creation failed. Please try again or contact support.'),
                    $e,
                );
            }
            throw $e;
        }
    }
}
