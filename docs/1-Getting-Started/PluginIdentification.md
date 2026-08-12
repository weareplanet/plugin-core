# Plugin Identification

Every API call can carry four headers naming the shop system and plugin behind it. The WeArePlanet Portal uses them to tell integrations apart — which platform a request came from, which version of it, and which version of your plugin — which is what makes a support case or an error report traceable back to a concrete installation.

| Header | Example | What it carries |
|---|---|---|
| `x-meta-shop-system` | `magento` | The platform name, as given. |
| `x-meta-shop-system-version` | `2.4.9` | The platform's full version. |
| `x-meta-shop-system-and-version` | `magento-2.4` | Platform and major.minor version combined. |
| `x-meta-plugin-version` | `1.2.0` | Your plugin's own version. |

## Key Components

- **`ClientMetadata`**: An immutable value object holding the three inputs — shop system name, shop system version, plugin version. Its `toHeaders(): array<string, string>` method is the single entry point that turns them into the four headers; callers never assemble header names themselves. The header names are also available as constants (`ClientMetadata::HEADER_SHOP_SYSTEM` and friends).
- **`ClientMetadataProviderInterface`**: What your plugin implements. One method, `getClientMetadata(): ?ClientMetadata`.

Both live in `WeArePlanet\PluginCore\Sdk`, alongside `SdkProvider` — the metadata is part of how the SDK client is configured, not a domain of its own.

## Usage

Implement the provider, resolving the versions however your platform exposes them:

```php
// The provider's whole contract: one method returning the three values.
public function getClientMetadata(): ?ClientMetadata
{
    return new ClientMetadata('magento', $this->platform->getVersion(), '1.2.0');
}
```

👉 **See this in action:** [plugin_identification.php](../examples/1-Getting-Started/plugin_identification.php)

Then pass it to `SdkProvider` as its second argument:

```php
use WeArePlanet\PluginCore\Sdk\SdkProvider;

$sdkProvider = new SdkProvider($settings, new MyShopClientMetadataProvider($platform, $modules));
```

Every call made through that provider — through any gateway or service built on it — now carries the four headers. There is nothing to pass at the call site.

## The metadata is optional

The argument is nullable and defaults to `null`. Omitting it, or returning `null` from `getClientMetadata()`, is a supported outcome rather than a failure: no identification headers are sent and every API call works exactly as it would otherwise. That matters when a plugin cannot determine its versions on some installations — a missing module registry entry, say — since identification is never worth failing a payment over.

```php
// Both are valid, and neither sends identification headers.
$sdkProvider = new SdkProvider($settings);
$sdkProvider = new SdkProvider($settings, $providerThatReturnsNull);
```

## How the combined version is derived

`x-meta-shop-system-and-version` lowercases the system name and keeps only the first two version segments, so `2.4.9` and `2.4.10` both report as `magento-2.4`. Patch releases are dropped deliberately: the WeArePlanet Portal groups installations by the feature version that actually changes integration behaviour.

| System | Version | Combined header |
|---|---|---|
| `magento` | `2.4.9` | `magento-2.4` |
| `magento` | `2.4` | `magento-2.4` |
| `Magento` | `2.4.9` | `magento-2.4` |
| `woocommerce` | `9` | `woocommerce-9` |
| `magento` | *(blank)* | `magento` |

Only the combined header is transformed. `x-meta-shop-system` and `x-meta-shop-system-version` report exactly what you passed in, casing and patch version included.

## Your headers do not replace the SDK's

The underlying SDK sends its own `x-meta-sdk-*` headers identifying the SDK version, language and provider. The identification headers are added alongside those; nothing is displaced on either API version.
