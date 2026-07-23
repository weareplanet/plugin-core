# Manual Task

Manual tasks are actions a merchant must complete in the WeArePlanet Portal before a transaction can proceed (e.g. a manual risk review). This feature lets a plugin check how many manual tasks are outstanding for a space, so it can surface a badge or reminder to the merchant.

## Key Components

- **ManualTaskGatewayInterface**: Exposes `countByState(int $spaceId, State $state): int`, returning the number of manual tasks in the given space that are in the given state.
- **State**: An enum of the possible manual task states — `OPEN`, `DONE`, `EXPIRED`.

## Usage

```php
use WeArePlanet\PluginCore\ManualTask\State;

$openCount = $manualTaskGateway->countByState($spaceId, State::OPEN);

if ($openCount > 0) {
    // Show a reminder badge to the merchant.
}
```

## Errors

`countByState()` throws `ManualTask\Exception\ManualTaskException` if the count cannot be retrieved. Like every other PluginCore exception, it exposes `isRetryable()` — see [Error Handling](../ErrorHandling.md).

## Reacting to State Changes

Instead of (or in addition to) polling `countByState()`, you can react to manual task state changes as they happen via a webhook listener. See [Webhook Processor](../Webhook/Processor/README.md) for handling incoming notifications.
