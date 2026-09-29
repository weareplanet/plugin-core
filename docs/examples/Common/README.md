# Common Example Helpers

This directory contains shared helper classes and a bootstrap script used by every runnable example under `docs/examples/`.

## Purpose

These files centralize common setup logic, such as:

- Autoloading dependencies.
- Reading configuration from environment variables.
- Providing a simple logger implementation.
- Providing a simple, file-based cache implementation.
- Handling session file persistence for transaction IDs.
- Initializing the SDK client.

## Files

- **bootstrap.php**: The main entry point for examples. It loads dependencies, validates credentials, and returns initialized services.
- **SimpleLogger.php**: A basic PSR-3 compatible logger that outputs to stdout.
- **SimpleCache.php**: A basic file-based cache (implements `WeArePlanet\PluginCore\SharedKernel\CacheInterface`), used by [fetch_global_data.php](../1-Getting-Started/fetch_global_data.php) to demonstrate caching label descriptors. For demonstration only — a real plugin points this at whatever cache the host application already has.
- **EnvSettingsProvider.php**: Reads settings (Space ID, User ID, API Secret) from environment variables.
- **FilePersistence.php**: Manages storing and retrieving transaction IDs in a local `session.json` file.
- **TransactionIdLoader.php**: Helper to load transaction IDs from CLI arguments or the session file.

## Usage in Examples

Example scripts should include `bootstrap.php` to get started quickly:

```php
$common = require __DIR__ . '/../Common/bootstrap.php';

$spaceId = $common['spaceId'];
$client = $common['sdkProvider'];
```

## Requirements

The following environment variables must be set:

- `PLUGINCORE_DEMO_SPACE_ID`
- `PLUGINCORE_DEMO_USER_ID`
- `PLUGINCORE_DEMO_API_SECRET`
