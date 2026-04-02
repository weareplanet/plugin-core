<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\SdkV2;

use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Token\State as StateEnum;
use WeArePlanet\PluginCore\Token\Token;
use WeArePlanet\PluginCore\Token\TokenGatewayInterface;
use WeArePlanet\Sdk\Model\Token as SdkToken;
use WeArePlanet\Sdk\Service\TransactionsService as SdkTransactionsService;
use WeArePlanet\Sdk\Service\TokensService as SdkTokensService;
use WeArePlanet\Sdk\Model\TokenCreate as SdkTokenCreate;

class TokenGateway implements TokenGatewayInterface
{
    private SdkTransactionsService $transactionsService;
    private SdkTokensService $tokensService;

    public function __construct(
        private readonly SdkProvider $sdkProvider,
        private readonly LoggerInterface $logger,
    ) {
        $this->transactionsService = $this->sdkProvider->getService(SdkTransactionsService::class);
        $this->tokensService = $this->sdkProvider->getService(SdkTokensService::class);
    }

    public function createToken(int $spaceId, int $transactionId): Token
    {
        $this->logger->debug("Creating/Fetching Token for Transaction $transactionId in Space $spaceId (V2).");

        // V2 Migration Note: Explicit 'createToken' from transaction service is not directly available or required.
        // Tokens are usually created during transaction processing if 'tokenizationMode' was set.
        // We will fetch the transaction and return the token attached to it.
        // If the token is not present, it might mean tokenization failed or was not requested.

        try {
            // Fetch transaction with token expanded
            $transaction = $this->transactionsService->getPaymentTransactionsId($transactionId, $spaceId, ['token']);
            $sdkToken = $transaction->getToken();

            if (!$sdkToken) {
                $this->logger->debug("Token not found on transaction $transactionId. Attempting to create a new token.");

                $tokenCreate = new SdkTokenCreate();
                $tokenCreate->setExternalId(uniqid((string)$transactionId . '_'));
                $tokenCreate->setCustomerId($transaction->getCustomerId());
                $tokenCreate->setTokenReference($transaction->getCustomerId()); // Use customer ID as reference

                if ($transaction->getCustomerEmailAddress()) {
                    $tokenCreate->setCustomerEmailAddress($transaction->getCustomerEmailAddress());
                }

                // Attempt to create token
                $sdkToken = $this->tokensService->postPaymentTokens($spaceId, $tokenCreate);
                $this->logger->debug("Successfully created new token with ID: " . $sdkToken->getId());
            }

            return $this->mapToDomain($sdkToken, $spaceId);
        } catch (\Exception $e) {
            $this->logger->error("Failed to fetch or create token for transaction $transactionId: " . $e->getMessage());
            throw $e;
        }
    }

    private function mapToDomain(SdkToken $sdkToken, int $spaceId): Token
    {
        $token = new Token();
        $token->id = $sdkToken->getId();
        $token->spaceId = $sdkToken->getLinkedSpaceId() ?? $spaceId;
        $token->version = $sdkToken->getVersion();

        $token->state = match ((string)$sdkToken->getState()) {
            'ACTIVE' => StateEnum::ACTIVE,
            'CREATE' => StateEnum::CREATE,
            'DELETED' => StateEnum::DELETED,
            'DELETING' => StateEnum::DELETING,
            'INACTIVE' => StateEnum::INACTIVE,
            default => StateEnum::ACTIVE,
        };

        return $token;
    }
}
