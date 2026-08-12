<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk;

use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Token\State as TokenState;
use WeArePlanet\PluginCore\Token\Token;
use WeArePlanet\PluginCore\Token\TokenVersion;
use WeArePlanet\PluginCore\Token\Version\State as TokenVersionState;
use WeArePlanet\Sdk\Model\PaymentConnector as SdkPaymentConnector;
use WeArePlanet\Sdk\Model\PaymentConnectorConfiguration as SdkPaymentConnectorConfiguration;
use WeArePlanet\Sdk\Model\PaymentMethodConfiguration as SdkPaymentMethodConfiguration;
use WeArePlanet\Sdk\Model\PaymentMethod as SdkPaymentMethod;
use WeArePlanet\Sdk\Model\Token as SdkToken;
use WeArePlanet\Sdk\Model\TokenVersion as SdkTokenVersion;

/**
 * Shared mapping trait for SDK Token objects to Domain Token objects.
 *
 * Centralizes the conversion of an SDK Token and its versions into domain
 * representations, ensuring all metadata like creation timestamp and customer
 * email/reference mapping are correctly populated without data loss across
 * standard and transaction flows.
 *
 * Consuming classes must also use {@see DomainLoggerTrait}: a state this library
 * does not model yet is reported through the logger rather than breaking a read.
 */
trait TokenMapperTrait
{
    use DateTimeMapperTrait;

    /**
     * Maps an SDK Token to a domain Token.
     *
     * Ensures all fields including state, both customer fields, and timestamps
     * are mapped to prevent metadata drops during serialization.
     *
     * The two customer fields are distinct and neither substitutes for the other:
     * customerId is the shop's own customer reference, suitable as a lookup key,
     * while customerIdentifier is a display value derived from the email address
     * or token reference.
     *
     * @param SdkToken $sdkToken The SDK token.
     * @param int|null $spaceId The space the call was made against, used when the
     *        payload carries no linked space of its own.
     * @return Token The mapped domain token.
     */
    protected function mapToToken(SdkToken $sdkToken, ?int $spaceId = null): Token
    {
        return new Token(
            id: (int)$sdkToken->getId(),
            state: match ((string) $sdkToken->getState()) {
                'ACTIVE' => TokenState::ACTIVE,
                'CREATE' => TokenState::CREATE,
                'DELETED' => TokenState::DELETED,
                'DELETING' => TokenState::DELETING,
                'INACTIVE' => TokenState::INACTIVE,
                default => TokenState::ACTIVE,
            },
            spaceId: $sdkToken->getLinkedSpaceId() ?? $spaceId,
            version: $sdkToken->getVersion(),
            customerId: $sdkToken->getCustomerId(),
            customerIdentifier: $sdkToken->getCustomerEmailAddress() ?? $sdkToken->getTokenReference(),
            createdOn: $this->toDateTimeImmutable($sdkToken->getCreatedOn()),
        );
    }

    /**
     * Maps an SDK TokenVersion, and the token it belongs to, to a domain TokenVersion.
     *
     * This API embeds the version's payment method and connector as objects; the domain
     * keeps only their IDs so a read costs the same on every API version.
     *
     * Two kinds of reference are mapped, and they must not be conflated: the connector
     * and payment-method *types* (global), and the *configuration* entities they were
     * set up under (space-scoped). The latter is what a plugin's synced payment-method
     * records are keyed by.
     *
     * The payload must carry its owning token: the domain entity holds it, and the
     * caller is expected to have rejected a payload without one rather than have a
     * placeholder invented here.
     *
     * @param SdkTokenVersion $sdkTokenVersion The SDK token version.
     * @param int|null $spaceId The space the call was made against, used when the
     *        payload carries no linked space of its own.
     * @return TokenVersion The mapped domain token version.
     */
    protected function mapToTokenVersion(SdkTokenVersion $sdkTokenVersion, ?int $spaceId = null): TokenVersion
    {
        $state = TokenVersionState::tryFrom((string) $sdkTokenVersion->getState());

        if ($state === null) {
            // An unrecognised state means the API grew one we do not model yet. Reading
            // must not break, so fall back to the non-final state and say so.
            $this->logger->warning('Unknown token version state reported by the API.', [
                'spaceId' => $spaceId,
                'tokenVersionId' => $sdkTokenVersion->getId(),
                'state' => $sdkTokenVersion->getState(),
            ]);
            $state = TokenVersionState::UNINITIALIZED;
        }

        $paymentMethodId = null;
        $sdkPaymentMethod = $sdkTokenVersion->getPaymentMethod();
        if ($sdkPaymentMethod instanceof SdkPaymentMethod) {
            $paymentMethodId = $sdkPaymentMethod->getId();
        }

        $connectorId = null;
        $connectorConfigurationId = null;
        $paymentMethodConfigurationId = null;
        $connectorConfiguration = $sdkTokenVersion->getPaymentConnectorConfiguration();
        if ($connectorConfiguration instanceof SdkPaymentConnectorConfiguration) {
            $sdkConnector = $connectorConfiguration->getConnector();
            if ($sdkConnector instanceof SdkPaymentConnector) {
                $connectorId = $sdkConnector->getId();
            }

            // The configuration's own ID, as opposed to the connector type it points
            // at. Both come from the object already read here, so neither costs a
            // further round trip.
            $connectorConfigurationId = $connectorConfiguration->getId();

            $paymentMethodConfiguration = $connectorConfiguration->getPaymentMethodConfiguration();
            if ($paymentMethodConfiguration instanceof SdkPaymentMethodConfiguration) {
                $paymentMethodConfigurationId = $paymentMethodConfiguration->getId();
            }
        }

        return new TokenVersion(
            id: (int)$sdkTokenVersion->getId(),
            token: $this->mapToToken($sdkTokenVersion->getToken(), $spaceId),
            state: $state,
            name: $sdkTokenVersion->getName(),
            linkedSpaceId: $sdkTokenVersion->getLinkedSpaceId() ?? $spaceId,
            connectorId: $connectorId !== null ? (int)$connectorId : null,
            paymentMethodId: $paymentMethodId !== null ? (int)$paymentMethodId : null,
            connectorConfigurationId: $connectorConfigurationId !== null ? (int)$connectorConfigurationId : null,
            paymentMethodConfigurationId: $paymentMethodConfigurationId !== null ? (int)$paymentMethodConfigurationId : null,
        );
    }
}
