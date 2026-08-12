<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk;

/**
 * Value Object identifying the shop system and plugin making an API call.
 *
 * The WeArePlanet Portal uses this to tell integrations apart: which platform a request came
 * from, which version of it, and which version of the plugin. That is what makes a
 * support case or an error report traceable back to a concrete installation.
 *
 * Instances are immutable. A plugin builds one — typically from its own version
 * constant and whatever version its platform reports — and hands it to
 * {@see SdkProvider} through a {@see ClientMetadataProviderInterface}.
 */
readonly class ClientMetadata
{
    /**
     * The platform name, e.g. `magento`.
     */
    public const HEADER_SHOP_SYSTEM = 'x-meta-shop-system';

    /**
     * The platform's full version, e.g. `2.4.9`.
     */
    public const HEADER_SHOP_SYSTEM_VERSION = 'x-meta-shop-system-version';

    /**
     * Platform and major.minor version combined, e.g. `magento-2.4`.
     */
    public const HEADER_SHOP_SYSTEM_AND_VERSION = 'x-meta-shop-system-and-version';

    /**
     * The plugin's own version, e.g. `1.2.0`.
     */
    public const HEADER_PLUGIN_VERSION = 'x-meta-plugin-version';

    /**
     * @param string $shopSystem The platform name, e.g. 'magento'. Lowercased when it
     *        appears in the combined header, and sent as given otherwise.
     * @param string $shopSystemVersion The platform's full version, e.g. '2.4.9'.
     * @param string $pluginVersion The shop plugin's own version, e.g. '1.2.0'.
     */
    public function __construct(
        public string $shopSystem,
        public string $shopSystemVersion,
        public string $pluginVersion,
    ) {
    }

    /**
     * Returns the identification headers to send with every API call.
     *
     * This is the single entry point: callers never assemble header names
     * themselves, so adding or renaming one is a change in this class alone.
     *
     * @return array<string, string> Header name to value.
     */
    public function toHeaders(): array
    {
        return [
            self::HEADER_SHOP_SYSTEM => $this->shopSystem,
            self::HEADER_SHOP_SYSTEM_VERSION => $this->shopSystemVersion,
            self::HEADER_SHOP_SYSTEM_AND_VERSION => $this->shopSystemAndVersion(),
            self::HEADER_PLUGIN_VERSION => $this->pluginVersion,
        ];
    }

    /**
     * Builds the combined platform and major.minor version string.
     *
     * Patch releases are dropped deliberately: the WeArePlanet Portal groups installations by
     * the feature version that actually changes integration behaviour, so '2.4.9'
     * and '2.4.10' both report as 'magento-2.4'.
     *
     * A version with a single segment is used as-is ('magento-3'), and a blank
     * version yields the bare system name rather than a dangling separator.
     *
     * @return string The combined identifier, e.g. 'magento-2.4'.
     */
    private function shopSystemAndVersion(): string
    {
        $system = strtolower($this->shopSystem);
        $majorMinor = implode('.', array_slice(explode('.', $this->shopSystemVersion), 0, 2));

        if ($majorMinor === '') {
            return $system;
        }

        return $system . '-' . $majorMinor;
    }
}
