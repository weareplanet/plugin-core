<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk;

use WeArePlanet\PluginCore\Token\State as TokenState;
use WeArePlanet\PluginCore\Token\Token;
use WeArePlanet\Sdk\Model\Token as SdkToken;

/**
 * Shared mapping trait for SDK Token objects to Domain Token objects.
 *
 * Centralizes the conversion of an SDK Token into a domain representation,
 * ensuring all metadata like creation timestamp and customer email/reference mapping
 * are correctly populated without data loss across standard and transaction flows.
 */
trait TokenMapperTrait
{
    use DateTimeMapperTrait;

    /**
     * Maps an SDK Token to a domain Token.
     *
     * Ensures all fields including state, customerIdentifier, and timestamps
     * are mapped to prevent metadata drops during serialization.
     *
     * @param SdkToken $sdkToken
     * @param int|null $spaceId
     * @return Token
     */
    protected function mapToToken(SdkToken $sdkToken, ?int $spaceId = null): Token
    {
        $token = new Token();
        $token->id = $sdkToken->getId();
        $token->spaceId = $sdkToken->getLinkedSpaceId() ?? $spaceId;
        $token->version = $sdkToken->getVersion();

        $token->state = match ((string) $sdkToken->getState()) {
            'ACTIVE' => TokenState::ACTIVE,
            'CREATE' => TokenState::CREATE,
            'DELETED' => TokenState::DELETED,
            'DELETING' => TokenState::DELETING,
            'INACTIVE' => TokenState::INACTIVE,
            default => TokenState::ACTIVE,
        };

        $token->customerIdentifier = $sdkToken->getCustomerEmailAddress() ?? $sdkToken->getTokenReference();
        $token->createdOn = $this->toDateTimeImmutable($sdkToken->getCreatedOn());

        return $token;
    }
}
