<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk;

use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\GlobalData\PaymentConnector\PaymentConnector;
use WeArePlanet\Sdk\Model\PaymentConnector as SdkPaymentConnector;
use WeArePlanet\Sdk\Model\PaymentConnectorFeature as SdkPaymentConnectorFeature;
use WeArePlanet\Sdk\Model\PaymentMethod as SdkPaymentMethod;
use WeArePlanet\Sdk\Model\PaymentProcessor as SdkPaymentProcessor;

/**
 * Shared mapping trait for SDK PaymentConnector objects to domain objects.
 *
 * This API embeds the payment method, processor and supported features as
 * whole entities; the domain keeps only their IDs so a read costs the same on
 * every API version.
 */
trait PaymentConnectorMapperTrait
{
    /**
     * Maps an SDK PaymentConnector to a domain PaymentConnector.
     *
     * @param SdkPaymentConnector $sdkConnector The SDK payment connector.
     * @return PaymentConnector The mapped domain payment connector.
     */
    protected function mapToPaymentConnector(SdkPaymentConnector $sdkConnector): PaymentConnector
    {
        $paymentMethodId = null;
        $sdkPaymentMethod = $sdkConnector->getPaymentMethod();
        if ($sdkPaymentMethod instanceof SdkPaymentMethod) {
            $paymentMethodId = $sdkPaymentMethod->getId();
        }

        $processorId = null;
        $sdkProcessor = $sdkConnector->getProcessor();
        if ($sdkProcessor instanceof SdkPaymentProcessor) {
            $processorId = $sdkProcessor->getId();
        }

        $supportedFeatureIds = [];
        foreach ($sdkConnector->getSupportedFeatures() ?? [] as $sdkFeature) {
            if ($sdkFeature instanceof SdkPaymentConnectorFeature && $sdkFeature->getId() !== null) {
                $supportedFeatureIds[] = (int)$sdkFeature->getId();
            }
        }

        $deprecationReason = $sdkConnector->getDeprecationReason();

        // The SDK documents this as an array of a pseudo-enum class, but the values it
        // actually returns are the bare constant strings of that class (e.g.
        // CustomersPresence::VIRTUAL_PRESENT === 'VIRTUAL_PRESENT'). Filtering by
        // is_string() reads that reality rather than the inaccurate docblock, and
        // doubles as a guard against a genuinely malformed entry.
        $supportedCustomersPresences = array_values(array_filter(
            $sdkConnector->getSupportedCustomersPresences() ?? [],
            'is_string',
        ));

        // Same story as above: the SDK documents this as a pseudo-enum class, but the
        // value it actually returns is that class's bare constant string.
        $primaryRiskTaker = $sdkConnector->getPrimaryRiskTaker();
        $primaryRiskTaker = is_string($primaryRiskTaker) ? $primaryRiskTaker : null;

        return new PaymentConnector(
            id: (int)$sdkConnector->getId(),
            name: new LocalizedString($sdkConnector->getName()),
            paymentMethodId: $paymentMethodId !== null ? (int)$paymentMethodId : null,
            processorId: $processorId !== null ? (int)$processorId : null,
            primaryRiskTaker: $primaryRiskTaker,
            supportedCurrencies: array_values($sdkConnector->getSupportedCurrencies() ?? []),
            supportedCustomersPresences: $supportedCustomersPresences,
            supportedFeatureIds: $supportedFeatureIds,
            deprecated: (bool)$sdkConnector->getDeprecated(),
            deprecationReason: $deprecationReason !== null ? new LocalizedString($deprecationReason) : null,
        );
    }
}
