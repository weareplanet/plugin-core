# Common Example Helpers

This directory contains shared helper classes and a bootstrap script used by the example integrations in `docs/`.

## Purpose

These files centralize common setup logic, such as:

- Autoloading dependencies.
- Reading configuration from environment variables.
- Providing a simple logger implementation.
- Handling session file persistence for transaction IDs.
- Initializing the SDK client.

## Files

- **bootstrap.php**: The main entry point for examples. It loads dependencies, validates credentials, and returns initialized services.
- **SimpleLogger.php**: A basic PSR-3 compatible logger that outputs to stdout.
- **EnvSettingsProvider.php**: Reads settings (Space ID, User ID, API Secret) from environment variables.
- **FilePersistence.php**: Manages storing and retrieving transaction IDs in a local `session.json` file.
- **TransactionIdLoader.php**: Helper to load transaction IDs from CLI arguments or the session file.

## Usage in Examples

Example scripts should include `bootstrap.php` to get started quickly:

```php
$common = require __DIR__ . '/../../examples/Common/bootstrap.php';

$spaceId = $common['spaceId'];
$client = $common['sdkProvider'];
```

## Requirements

The following environment variables must be set:

- `PLUGINCORE_DEMO_SPACE_ID`
- `PLUGINCORE_DEMO_USER_ID`
- `PLUGINCORE_DEMO_API_SECRET`
