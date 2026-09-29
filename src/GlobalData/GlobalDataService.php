<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\GlobalData;

use WeArePlanet\PluginCore\GlobalData\Currency\CurrencyCollection;
use WeArePlanet\PluginCore\GlobalData\Exception\GlobalDataException;
use WeArePlanet\PluginCore\GlobalData\LabelDescriptor\LabelDescriptorCollection;
use WeArePlanet\PluginCore\GlobalData\LabelDescriptorGroup\LabelDescriptorGroupCollection;
use WeArePlanet\PluginCore\GlobalData\Language\LanguageCollection;
use WeArePlanet\PluginCore\GlobalData\PaymentConnector\PaymentConnectorCollection;
use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;

/**
 * Domain-facing facade for the WeArePlanet Portal's global reference data.
 *
 * This is the single entry point consumers use for currencies, languages,
 * payment connectors, and label descriptors and their groups. It delegates to the
 * configured {@see GlobalDataGatewayInterface}, which owns the API interaction,
 * its logging, its failure handling, and — for label descriptors and their
 * groups — its own caching behavior. The service deliberately adds no logic of
 * its own beyond that delegation: doing so would record every failure twice,
 * re-wrap exceptions that are already domain exceptions, or duplicate caching
 * state that only the gateway needs.
 *
 * None of these methods take a space ID — this data is global to the WeArePlanet Portal, not
 * scoped to a merchant.
 *
 * Queries over data already read — resolving a locale to its primary variant, or
 * looking an entity up by ID — live on the returned collections rather than here,
 * so they cost nothing beyond the one read that produced the collection.
 *
 * ## Caching label descriptors and their groups
 *
 * {@see getLabelDescriptors()} and {@see getLabelDescriptorGroups()} take no
 * cache-related parameters: those describe how a call is served, not what a
 * label descriptor or group is, so they do not belong on a method signature a
 * consumer reads to understand what they get back. Instead, {@see setCacheTtl()}
 * and {@see setForceRefresh()} — plain passthroughs to the same methods on
 * {@see GlobalDataGatewayInterface}, which is where the caching actually happens
 * — let a consumer control this dynamically. Nothing changes for a client that
 * never configures a cache on {@see \WeArePlanet\PluginCore\Sdk\SdkProvider}
 * or never calls either method: every read behaves exactly as it did before
 * caching existed.
 */
#[LogContext(domain: 'global_data')]
class GlobalDataService
{
    use DomainLoggerTrait;

    /**
     * @param GlobalDataGatewayInterface $gateway The gateway that owns the API
     *        interaction, and the caching behavior of its label descriptor reads.
     * @param LoggerInterface $logger The logger instance.
     * @param int|null $defaultCacheTtl The default TTL, in seconds, for
     *        {@see getLabelDescriptors()} and {@see getLabelDescriptorGroups()}
     *        when a cache is configured on the gateway; null defers to the
     *        gateway's own default. A convenience equivalent to calling
     *        {@see setCacheTtl()} immediately after construction — overridable at
     *        any time the same way.
     */
    public function __construct(
        private readonly GlobalDataGatewayInterface $gateway,
        LoggerInterface $logger,
        ?int $defaultCacheTtl = null,
    ) {
        $this->initializeLogger($logger);

        if ($defaultCacheTtl !== null) {
            $this->gateway->setCacheTtl($defaultCacheTtl);
        }
    }

    /**
     * Clears any cached label descriptor groups, if a cache is configured.
     *
     * A no-op when no cache was configured.
     *
     * @return void
     */
    public function clearLabelDescriptorGroupsCache(): void
    {
        $this->gateway->clearLabelDescriptorGroupsCache();
    }

    /**
     * Clears any cached label descriptors, if a cache is configured.
     *
     * A no-op when no cache was configured.
     *
     * @return void
     */
    public function clearLabelDescriptorsCache(): void
    {
        $this->gateway->clearLabelDescriptorsCache();
    }

    /**
     * Returns every currency the WeArePlanet Portal supports.
     *
     * @return CurrencyCollection The supported currencies.
     * @throws GlobalDataException If the currencies cannot be retrieved.
     */
    public function getCurrencies(): CurrencyCollection
    {
        return $this->gateway->getCurrencies();
    }

    /**
     * Returns every label descriptor group the WeArePlanet Portal defines.
     *
     * See {@see getLabelDescriptors()} for the caching behavior this is subject to.
     *
     * @return LabelDescriptorGroupCollection The label descriptor groups.
     * @throws GlobalDataException If the groups cannot be retrieved.
     */
    public function getLabelDescriptorGroups(): LabelDescriptorGroupCollection
    {
        return $this->gateway->getLabelDescriptorGroups();
    }

    /**
     * Returns every label descriptor the WeArePlanet Portal defines.
     *
     * This catalogue is global and rarely changes, so it may be cached when the
     * client has configured a cache (see {@see \WeArePlanet\PluginCore\Sdk\SdkProvider::getCache()}).
     * See {@see setCacheTtl()} and {@see setForceRefresh()} for dynamic control
     * over that. Without a configured cache, this reads through to the API
     * exactly as before caching existed.
     *
     * @return LabelDescriptorCollection The label descriptors.
     * @throws GlobalDataException If the descriptors cannot be retrieved.
     */
    public function getLabelDescriptors(): LabelDescriptorCollection
    {
        return $this->gateway->getLabelDescriptors();
    }

    /**
     * Returns every language the WeArePlanet Portal supports.
     *
     * @return LanguageCollection The supported languages.
     * @throws GlobalDataException If the languages cannot be retrieved.
     */
    public function getLanguages(): LanguageCollection
    {
        return $this->gateway->getLanguages();
    }

    /**
     * Returns every payment connector the WeArePlanet Portal defines.
     *
     * @return PaymentConnectorCollection The payment connectors.
     * @throws GlobalDataException If the connectors cannot be retrieved.
     */
    public function getPaymentConnectors(): PaymentConnectorCollection
    {
        return $this->gateway->getPaymentConnectors();
    }

    /**
     * Sets how long a label descriptor or group result may be cached for, in
     * seconds, when a cache is configured. A plain passthrough to the same
     * method on {@see GlobalDataGatewayInterface}, which is where the caching
     * actually happens.
     *
     * @param int|null $ttl The TTL, in seconds, or null to defer to the
     *        gateway's own default.
     * @return void
     */
    public function setCacheTtl(?int $ttl): void
    {
        $this->gateway->setCacheTtl($ttl);
    }

    /**
     * Enables or disables bypassing the cache on every subsequent
     * {@see getLabelDescriptors()} / {@see getLabelDescriptorGroups()} call. A
     * plain passthrough to the same method on {@see GlobalDataGatewayInterface}.
     *
     * Sticky, not one-shot: once enabled, every call bypasses — and
     * repopulates — the cache until this is called again with false.
     *
     * @param bool $forceRefresh True to bypass the cache from now on.
     * @return void
     */
    public function setForceRefresh(bool $forceRefresh = true): void
    {
        $this->gateway->setForceRefresh($forceRefresh);
    }
}
