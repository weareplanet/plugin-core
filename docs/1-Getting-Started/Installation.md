# Installation

PluginCore is a Composer library. It carries no framework dependency and no storage layer of its own, so it drops into a shop plugin without dictating how that plugin is built.

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.2 or newer |
| Composer | 2.x |

The WeArePlanet SDK is pulled in automatically as a dependency — you do not need to require it yourself.

## Install

```bash
composer require weareplanet/plugin-core
```

Then make sure Composer's autoloader is loaded, which most frameworks do for you:

```php
require_once __DIR__ . '/vendor/autoload.php';
```

## Configure Credentials

Every API call is authenticated with a WeArePlanet Portal **User ID** and **API Secret**, and most are scoped to a **Space ID**. PluginCore reads these through a `SettingsProvider` you implement, so the credentials can come from wherever your platform already keeps configuration — a settings table, environment variables, or a config file.

```php
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Settings\Settings;

$settings = new Settings($mySettingsProvider);
$sdkProvider = new SdkProvider($settings);
```

`SdkProvider` is the single entry point the rest of the library is built on: every gateway takes one, and it owns the configured API client.

> [!TIP]
> Pass a `ClientMetadataProviderInterface` as the second argument to identify your shop system and plugin version on every call. It is optional, but it is what makes a support case traceable back to a concrete installation — see [Plugin Identification](PluginIdentification.md).

## Verify the Install

The quickest check that credentials work is a global-data lookup, because it needs no Space ID and touches no merchant data:

```php
$currencies = $globalDataService->getCurrencies();
```

If that returns a populated collection, the client is configured correctly. See [Global Data](GlobalData.md) for the full set of lookups.

## Running the Examples

The runnable scripts under [`docs/examples/`](../examples/) are the fastest way to see a feature end to end. They are driven by environment variables rather than a settings implementation — see the [documentation index](../README.md#examples) for the variables to set.

## Next Steps

- [Error Handling](ErrorHandling.md) — how PluginCore reports failures, and which ones are worth retrying.
- [Checkout](../2-Checkout-Flow/Checkout.md) — create your first transaction.
