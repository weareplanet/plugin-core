<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk;

use WeArePlanet\PluginCore\DeliveryIndication\DeliveryIndication;
use WeArePlanet\PluginCore\DeliveryIndication\State;
use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\Sdk\Model\DeliveryIndication as SdkDeliveryIndication;
use WeArePlanet\Sdk\Model\Transaction as SdkTransaction;

/**
 * Shared mapping trait for SDK DeliveryIndication objects to domain objects.
 *
 * Centralizes the conversion of an SDK delivery indication into the domain
 * {@see DeliveryIndication}, keeping the payload-shape differences between API
 * versions out of the gateways that call it.
 *
 * Consuming classes must also use {@see DomainLoggerTrait}: a state this library
 * does not model yet is reported through the logger rather than breaking a read.
 */
trait DeliveryIndicationMapperTrait
{
    /**
     * Maps an SDK DeliveryIndication to a domain DeliveryIndication.
     *
     * This API reports the completion as a bare ID; the domain keeps it as an ID so a
     * read costs the same on every API version.
     *
     * @param SdkDeliveryIndication $sdkIndication The SDK delivery indication.
     * @param int|null $spaceId The space the call was made against, used when the
     *        payload carries no linked space of its own.
     * @return DeliveryIndication The mapped domain delivery indication.
     */
    protected function mapToDeliveryIndication(
        SdkDeliveryIndication $sdkIndication,
        ?int $spaceId = null,
    ): DeliveryIndication {
        $state = State::tryFrom((string) $sdkIndication->getState());

        if ($state === null) {
            // An unrecognised state means the API grew one we do not model yet. Reading
            // must not break, so fall back to the non-final state and say so.
            $this->logger->warning('Unknown delivery indication state reported by the API.', [
                'spaceId' => $spaceId,
                'indicationId' => $sdkIndication->getId(),
                'state' => $sdkIndication->getState(),
            ]);
            $state = State::PENDING;
        }

        $completionId = $sdkIndication->getCompletion();

        // The SDK declares the embedded transaction as always present, but the API omits
        // it on sparse payloads, so the linked ID is preferred and the embedded entity is
        // only consulted as a fallback.
        $transactionId = $sdkIndication->getLinkedTransaction();
        if ($transactionId === null) {
            $sdkTransaction = $sdkIndication->getTransaction();
            $transactionId = $sdkTransaction instanceof SdkTransaction ? $sdkTransaction->getId() : null;
        }

        return new DeliveryIndication(
            id: (int)$sdkIndication->getId(),
            spaceId: (int)($sdkIndication->getLinkedSpaceId() ?? $spaceId),
            state: $state,
            transactionId: $transactionId !== null ? (int)$transactionId : null,
            completionId: $completionId !== null ? (int)$completionId : null,
        );
    }
}
