<?php

namespace MyPlugin\ExampleChargeImplementation;

/**
 * Charge Attempt Labels Example
 *
 * This script fetches the successful charge attempt of a transaction and prints
 * the labels the payment processor reported for it.
 *
 * USAGE:
 * php 7_fetch_charge_attempt_labels.php [transaction_id]
 */

use WeArePlanet\PluginCore\Charge\ChargeService;
use WeArePlanet\PluginCore\Charge\Exception\ChargeException;
use WeArePlanet\PluginCore\Examples\Common\TransactionIdLoader;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\ChargeGateway;

// 📖 Concept documentation: See docs/2-Checkout-Flow/Charge.md

error_reporting(E_ALL & ~E_DEPRECATED);

/** @var array $common */
$common = require __DIR__ . '/../Common/bootstrap.php';

$spaceId = $common['spaceId'];
$sdkProvider = $common['sdkProvider'];
$logger = $common['logger'];

try {
    $transactionId = TransactionIdLoader::load($argv);
} catch (\Exception $e) {
    exit($e->getMessage());
}

// One service covers both halves of the domain: applying a charge flow, and
// reading the attempt it produced. A plugin wires this up once, in its DI
// container, and injects it wherever a charge is triggered.
$charge = new ChargeService(new ChargeGateway($sdkProvider, $logger), $logger);

echo "Fetching successful charge attempt for Transaction ID: $transactionId\n";

try {
    $chargeAttempt = $charge->findSuccessfulAttemptByTransaction($spaceId, $transactionId);
} catch (ChargeException $e) {
    echo "ERROR: {$e->getMessage()}\n";
    echo $e->isRetryable() ? "This failure looks transient — retrying may help.\n" : "This failure is terminal.\n";
    exit(1);
}

// No successful attempt is a normal outcome: the transaction may still be
// pending, or its charge may have failed.
if ($chargeAttempt === null) {
    echo "No successful charge attempt found for this transaction.\n";
    exit(0);
}

echo "Charge Attempt ID: {$chargeAttempt->id}\n";
echo "State: {$chargeAttempt->state}\n";
echo "Labels (" . count($chargeAttempt->labels) . "):\n";

foreach ($chargeAttempt->labels as $label) {
    // groupName is populated only where the API returns the group inline;
    // WebServiceAPIV1 reports the group as a bare ID, so it stays null here.
    $group = $label->groupName?->localize('en-US') ?? $label->groupId ?? 'ungrouped';

    echo "  [{$label->descriptorId}] {$label->content} (group: {$group})\n";
}

// Reading one specific label: replace 1001 with a descriptor ID from your WeArePlanet Portal.
$singleLabel = $chargeAttempt->getLabel(1001);
echo "\nLabel 1001: " . ($singleLabel?->content ?? 'not present on this attempt') . "\n";

// Reading every label of one group: replace '4' with a group ID from your WeArePlanet Portal.
$grouped = $chargeAttempt->getLabelsByGroup('4');
echo "Labels in group 4: " . count($grouped) . "\n";

// The successful attempt above is a filter over the full list. Read the whole list
// when the failed runs matter too — showing a customer why a payment was retried,
// for instance. isSuccessful() is what tells them apart.
$allAttempts = $charge->findAllAttemptsByTransaction($spaceId, $transactionId);
echo "\nAll charge attempts (" . count($allAttempts) . "):\n";

foreach ($allAttempts as $attempt) {
    $marker = $attempt->isSuccessful() ? ' <- successful' : '';
    echo "  [{$attempt->id}] {$attempt->state}{$marker}\n";
}

// ---------------------------------------------------------------------------
// The other half of this domain: charging the transaction in the first place.
// ---------------------------------------------------------------------------
// Not executed here — applying a charge flow moves real money, so this example
// stays read-only. The call a plugin makes is:
//
//   $transaction = $charge->applyFlow($spaceId, $transactionId);
//
// The flow runs asynchronously, so the returned transaction reflects the state
// at the moment the flow was applied (typically PROCESSING), not the final
// outcome. Read the transaction again, or handle the webhook, to learn how it
// ended. Failures arrive as the same ChargeException caught above.
