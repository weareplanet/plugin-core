<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk;

use WeArePlanet\PluginCore\GlobalData\PaymentConnector\PaymentConnector;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\Sdk\Model\PaymentConnector as SdkPaymentConnector;

/**
 * Shared mapping trait for SDK PaymentConnector objects to domain objects.
 *
 * This API reports the payment method, processor and supported features as
 * bare IDs; the domain keeps them as IDs so a read costs the same on every API
 * version.
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
        $paymentMethodId = $sdkConnector->getPaymentMethod();
        $processorId = $sdkConnector->getProcessor();
        $deprecationReason = $sdkConnector->getDeprecationReason();

        // The SDK documents these as arrays of a pseudo-enum class, but the values it
        // actually returns are the bare constant strings of that class (e.g.
        // CustomersPresence::VIRTUAL_PRESENT === 'VIRTUAL_PRESENT'). Filtering by
        // is_string() reads that reality rather than the inaccurate docblock, and
        // doubles as a guard against a genuinely malformed entry.
        $supportedCurrencies = array_values(array_filter(
            $sdkConnector->getSupportedCurrencies() ?? [],
            'is_string',
        ));
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
            supportedCurrencies: $supportedCurrencies,
            supportedCustomersPresences: $supportedCustomersPresences,
            supportedFeatureIds: array_map('intval', $sdkConnector->getSupportedFeatures() ?? []),
            deprecated: (bool)$sdkConnector->getDeprecated(),
            deprecationReason: $deprecationReason !== null ? new LocalizedString($deprecationReason) : null,
        );
    }
}
