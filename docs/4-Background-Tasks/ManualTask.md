# Manual Task

Manual tasks are actions a merchant must complete in the WeArePlanet Portal before a transaction can proceed (e.g. a manual risk review). This feature lets a plugin check how many manual tasks are outstanding for a space, so it can surface a badge or reminder to the merchant.

## Key Components

- **ManualTaskService**: The entry point consumers use. Exposes `countByState(int $spaceId, State $state): int`, returning the number of manual tasks in the given space that are in the given state, and `countAll(int $spaceId): int` for the total whatever the state.
- **ManualTaskGatewayInterface**: The infrastructure port the service delegates to. Consumers do not call it directly.
- **State**: An enum of the possible manual task states — `OPEN`, `DONE`, `EXPIRED`.

## Usage

```php
use WeArePlanet\PluginCore\ManualTask\State;

$openCount = $manualTaskService->countByState($spaceId, State::OPEN);
```

👉 **See this in action:** [manual_tasks.php](../examples/4-Background-Tasks/manual_tasks.php)

> [!WARNING]
> **A count of zero does not always mean there are no manual tasks.** The API returns `0` — not an error — when the API user lacks permission to read them, so an under-permissioned integration silently reports an empty queue while the merchant has work waiting.
>
> If the WeArePlanet Portal lists tasks but a count comes back zero, check in this order:
>
> 1. **Permissions.** The API user needs `Root >> Account Admin >> Space >> Task >> Read`. Without it every count returns zero, quietly. Reading a task by ID does fail loudly with a permission error, which is the quickest way to confirm this.
> 2. **Space.** Manual tasks are scoped to a space; the WeArePlanet Portal's view may be scoped to a different one than you queried.
>
> `countAll()` does not separate these two — it goes through the same endpoint and returns zero in both cases.

Note: `countByState()` may issue multiple API calls for spaces with a large number of matching tasks. Avoid calling it in a tight loop or hot request path; cache the result if you need it frequently.

## Errors

`countByState()` throws `ManualTask\Exception\ManualTaskException` if the count cannot be retrieved. Like every other PluginCore exception, it exposes `isRetryable()` — see [Error Handling](../1-Getting-Started/ErrorHandling.md).

## Reacting to State Changes

Instead of (or in addition to) polling `countByState()`, you can react to manual task state changes as they happen via a webhook listener. See [Webhook Processor](Webhook-Processor.md) for handling incoming notifications.
