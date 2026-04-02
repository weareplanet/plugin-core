# Webhook Management

The Webhook Management module allows to programmatically manage webhook subscriptions in the Portal and validate incoming payloads.

## Overview

A webhook consists of two parts in the Portal:

1. **Webhook URL**: The endpoint where the Portal will send notifications.
2. **Webhook Listener**: The rule that defines which entity and state changes trigger a notification to a specific URL.

The module provides a unified `WebhookService` to handle these entities.

## Key Components

- **WebhookService**: Orchestrates installation (URL + Listener), uninstallation, and updates.
- **WebhookConfig**: DTO carrying configuration data (URL, name, entity ID, state ID).
- **WebhookManagementGatewayInterface**: Abstraction for creating/updating/deleting webhook entities.
- **WebhookSignatureGatewayInterface**: Abstraction for validating payload signatures.

## Installation Flow

When installing a webhook, the service:

1. Creates the **Webhook URL** definition.
2. Uses the resulting ID to create a **Webhook Listener**.

```php
use WeArePlanet\PluginCore\Webhook\WebhookConfig;
use WeArePlanet\PluginCore\Webhook\WebhookService;

// Config: URL, Internal Name, Entity (Transaction), Event State (Authorized)
$config = new WebhookConfig(
    url: 'https://your-shop.com/webhook/callback',
    name: 'Order Authorization Listener',
    entity: \WeArePlanet\PluginCore\Webhook\Enum\WebhookListener::TRANSACTION,
    eventStates: [\WeArePlanet\PluginCore\Transaction\State::AUTHORIZED->value]
);

$webhookService->installWebhook($spaceId, $config);
```

## Management Operations

### Updating the URL

If you need to move your endpoint, you can update the URL definition. The implementation handles the required **Optimistic Locking** (Read-Modify-Write) automatically.

```php
$webhookService->updateWebhookUrl($spaceId, $webhookUrlId, 'https://new-url.com/callback');
```

### Uninstallation

Correctly removes both the listener and the URL definition. If listener deletion fails, it still attempts to clean up the URL.

```php
$webhookService->uninstallWebhook($spaceId, $webhookUrlId, $listenerId);
```

## Usage Example

See the [example](example/) directory for a fully working CLI script that demonstrates the full lifecycle:

1. Creating a Webhook.
2. Updating its URL.
3. Cleaning up (Uninstallation).

> [!TIP]
> Use the `WebhookConfig` to manage different states (e.g., FAILED, SUCCESSFUL) by creating multiple listeners pointing to the same Webhook URL.
