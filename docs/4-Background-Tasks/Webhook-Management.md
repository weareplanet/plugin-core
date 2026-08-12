# Webhook Management

The Webhook Management module allows developers to programmatically manage webhook subscriptions in the WeArePlanet Portal and validate incoming payloads.

## Overview

A webhook consists of two parts in the WeArePlanet Portal:

1. **Webhook URL**: The endpoint where the WeArePlanet Portal will send notifications.
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
$config = new WebhookConfig(
    url: 'https://your-shop.com/webhook/callback', name: 'Order Authorization Listener',
    entity: WebhookListener::TRANSACTION, eventStates: [TransactionState::AUTHORIZED->value],
);
$webhookUrl = $webhookService->installWebhook($spaceId, $config); // returns the created WebhookUrl
```

👉 **See this in action:** [manage_webhooks.php](../examples/4-Background-Tasks/manage_webhooks.php)

## Management Operations

### Updating the URL

If you need to move your endpoint, you can update the URL definition. The implementation handles the required **Optimistic Locking** (Read-Modify-Write) automatically.

```php
$webhookService->updateWebhookUrl($spaceId, $webhookUrlId, 'https://new-url.com/callback');
```

### Uninstallation

Deletes the listener, then the URL definition. If listener deletion fails, the operation stops there and the URL is left in place, so you can safely retry.

```php
$webhookService->uninstallWebhook($spaceId, $webhookUrlId, $listenerId);
```

## Usage Example

See the [example](../examples/4-Background-Tasks) directory for a fully working CLI script that demonstrates the full lifecycle:

1. Creating a Webhook.
2. Updating its URL.
3. Cleaning up (Uninstallation).

> [!TIP]
> Use the `WebhookConfig` to manage different states (e.g., FAILED, SUCCESSFUL) by creating multiple listeners pointing to the same Webhook URL.
