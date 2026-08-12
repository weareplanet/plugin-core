<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk;

/**
 * Supplies the shop system and plugin identification sent with every API call.
 *
 * A shop plugin implements this and hands it to {@see SdkProvider}. It is an
 * interface rather than a plain {@see ClientMetadata} argument because the values
 * usually come from the platform at runtime — a version read from the shop's own
 * module registry, say — which a plugin resolves through its container rather than
 * having available when the provider is constructed.
 */
interface ClientMetadataProviderInterface
{
    /**
     * Returns the metadata identifying this integration.
     *
     * Returning null is a supported answer, not a failure: an integration that
     * cannot determine its versions simply sends no identification headers, and
     * every API call still works.
     *
     * @return ClientMetadata|null The metadata, or null when none is available.
     */
    public function getClientMetadata(): ?ClientMetadata;
}
