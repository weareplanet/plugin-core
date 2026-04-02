<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\SdkV1;

use Psr\Log\LoggerInterface;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Token\State as StateEnum;
use WeArePlanet\PluginCore\Token\Token;
use WeArePlanet\PluginCore\Token\TokenGatewayInterface;
use WeArePlanet\Sdk\Model\Token as SdkToken;
use WeArePlanet\Sdk\Service\TokenService as SdkTokenService;

/**
 * SDK implementation of the TokenGatewayInterface.
 */
class TokenGateway implements TokenGatewayInterface
{
    private SdkTokenService $tokenService;

    public function __construct(SdkProvider $sdkProvider, LoggerInterface $logger)
    {
        $this->tokenService = $sdkProvider->getService(SdkTokenService::class);
    }

    public function createToken(int $spaceId, int $transactionId): Token
    {
        $sdkToken = $this->tokenService->createToken($spaceId, $transactionId);
        return $this->mapToDomain($sdkToken);
    }

    private function mapToDomain(SdkToken $sdkToken): Token
    {
        $token = new Token();
        $token->id = $sdkToken->getId();
        $token->spaceId = $sdkToken->getLinkedSpaceId();
        $token->version = $sdkToken->getVersion();

        // Map State
        $stateString = (string)$sdkToken->getState();
        $token->state = StateEnum::tryFrom($stateString) ?? StateEnum::ACTIVE; // Fallback to ACTIVE if unknown

        return $token;
    }
}
