<?php

namespace MyPlugin\ExampleCompletionImplementation;

/**
 * Completion Verification Example
 *
 * Demonstrates the read-side of the completion gateway:
 * - get():  returns the completion or throws a CompletionException.
 * - find(): returns the completion or null when it does not exist (404).
 *
 * Completions are asynchronous: capture() may return a PENDING completion,
 * and the WeArePlanet Portal delivers the final outcome later via a TransactionCompletion
 * webhook. Webhook handlers should use these methods to re-read the source
 * of truth instead of trusting the webhook payload.
 *
 * USAGE:
 * php find_completion.php <completion_id>
 * (Run capture.php first — it prints the completion ID.)
 */

use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\TransactionCompletionGateway;
use WeArePlanet\PluginCore\Transaction\Completion\Exception\CompletionException;
use WeArePlanet\PluginCore\Transaction\Completion\TransactionCompletionService;

// 📖 Concept documentation: See docs/3-Post-Payment/Completion.md

error_reporting(E_ALL & ~E_DEPRECATED);

/** @var array $common */
$common = require __DIR__ . '/../Common/bootstrap.php';

$spaceId = (int)$common['spaceId'];
$sdkProvider = $common['sdkProvider'];
$logger = $common['logger'];

$completionId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($completionId <= 0) {
    exit("USAGE: php find_completion.php <completion_id>\n");
}

echo "Verifying Completion ID: $completionId\n";

// Consumers talk to the domain service, never to the gateway directly.
$completionService = new TransactionCompletionService(
    new TransactionCompletionGateway($sdkProvider, $logger),
    $logger,
);

// GET: returns the completion or throws a domain CompletionException.
try {
    $completion = $completionService->get($spaceId, $completionId);

    echo "\n[Completion]\n";
    echo " > ID: {$completion->id}\n";
    echo " > Transaction: {$completion->linkedTransactionId}\n";
    echo " > State: {$completion->state->value}\n";
    if ($completion->failureReason !== null) {
        echo " > Failure Reason: " . $completion->failureReason->localize('en-US') . "\n";
    }
} catch (CompletionException $e) {
    exit("\n[FAILED] " . $e->getMessage() . "\n");
}

// FIND: returns null instead of throwing when the completion does not exist.
$missing = $completionService->find($spaceId, 999999999);
echo "\nfind() for an unknown ID returned: " . ($missing === null ? 'null' : 'a completion') . "\n";
