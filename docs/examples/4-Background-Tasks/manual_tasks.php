<?php

namespace MyPlugin\ExampleManualTaskImplementation;

/**
 * Manual Task Example
 *
 * This script counts the manual tasks outstanding in a space, which is what a plugin
 * uses to surface a reminder badge to the merchant in its admin UI.
 *
 * USAGE:
 * php manual_tasks.php
 */

use WeArePlanet\PluginCore\ManualTask\Exception\ManualTaskException;
use WeArePlanet\PluginCore\ManualTask\ManualTaskService;
use WeArePlanet\PluginCore\ManualTask\State;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\ManualTaskGateway;

// 📖 Concept documentation: See docs/4-Background-Tasks/ManualTask.md

error_reporting(E_ALL & ~E_DEPRECATED);

/** @var array $common */
$common = require __DIR__ . '/../Common/bootstrap.php';

$spaceId = $common['spaceId'];
$sdkProvider = $common['sdkProvider'];
$logger = $common['logger'];

// Consumers talk to the domain service, never to the gateway directly.
$manualTaskService = new ManualTaskService(new ManualTaskGateway($sdkProvider, $logger), $logger);

try {
    // Total first, as a sanity check. Be careful reading a zero here: the API
    // returns 0 rather than an error when the API user lacks permission to read
    // manual tasks, so zero means "none visible to this user in this space",
    // not necessarily "none exist".
    $total = $manualTaskService->countAll((int)$spaceId);
    echo "Manual tasks in space $spaceId (any state): $total" . PHP_EOL;

    // OPEN is the state worth surfacing: it is work the merchant still has to do.
    $openCount = $manualTaskService->countByState((int)$spaceId, State::OPEN);

    echo "Open manual tasks in space $spaceId: $openCount" . PHP_EOL;

    if ($openCount > 0) {
        echo "Show a reminder badge in the merchant's admin UI." . PHP_EOL;
    }

    // The other states are mostly useful for reporting rather than for a badge.
    foreach ([State::DONE, State::EXPIRED] as $state) {
        echo "  {$state->value}: " . $manualTaskService->countByState((int)$spaceId, $state) . PHP_EOL;
    }

    if ($total === 0) {
        // A zero here is ambiguous: the API returns 0 rather than an error when the
        // API user cannot read manual tasks, so this is not proof the space is empty.
        echo PHP_EOL
            . "Zero manual tasks reported. If the WeArePlanet Portal lists some," . PHP_EOL
            . "this is most likely one of:" . PHP_EOL
            . "  1. Permissions — the API user needs 'Space >> Task >> Read'. Without it" . PHP_EOL
            . "     every count returns 0 silently." . PHP_EOL
            . "  2. Space — the WeArePlanet Portal view may be scoped to a" . PHP_EOL
            . "     space other than {$spaceId}." . PHP_EOL;
    } elseif ($openCount === 0) {
        echo PHP_EOL
            . "The space has tasks but none are OPEN — they are all DONE or EXPIRED." . PHP_EOL;
    }
} catch (ManualTaskException $e) {
    echo "Error: " . ($e->getLocalizedMessage()?->localize('en-US') ?? $e->getMessage()) . PHP_EOL;
}
