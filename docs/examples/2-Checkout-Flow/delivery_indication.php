<?php

namespace MyPlugin\ExampleDeliveryIndicationImplementation;

/**
 * Delivery Indication Example
 *
 * This script demonstrates the manual delivery review flow: finding the indication
 * raised for a transaction, and recording the merchant's decision on it.
 *
 * NOTE: An indication only exists once the payment is captured AND the WeArePlanet Portal
 * selected the transaction for review, so this script often finds nothing.
 *
 * USAGE:
 * php delivery_indication.php [transaction_id]
 */

use WeArePlanet\PluginCore\DeliveryIndication\DeliveryIndicationService;
use WeArePlanet\PluginCore\DeliveryIndication\Exception\DeliveryIndicationException;
use WeArePlanet\PluginCore\Examples\Common\TransactionIdLoader;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\DeliveryIndicationGateway;

// 📖 Concept documentation: See docs/2-Checkout-Flow/DeliveryIndication.md

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

// Consumers talk to the domain service, never to the gateway directly.
$indicationService = new DeliveryIndicationService(
    new DeliveryIndicationGateway($sdkProvider, $logger),
    $logger,
);

try {
    // findByTransaction() returns null rather than throwing when there is nothing to review.
    $indication = $indicationService->findByTransaction((int)$spaceId, $transactionId);

    if ($indication === null) {
        echo "No delivery indication for transaction $transactionId." . PHP_EOL;
        echo "The payment may not be captured, or was never selected for review." . PHP_EOL;
        exit(0);
    }

    echo "Indication {$indication->id}: state {$indication->state->value}" . PHP_EOL;

    if (!$indication->isDecisionPending()) {
        echo "Already decided — nothing to do." . PHP_EOL;
        exit(0);
    }

    // The merchant approved the order in your admin UI. Both markAs* calls return
    // the updated indication, so there is no need to re-read it afterwards.
    $decided = $indicationService->markAsSuitable((int)$spaceId, $indication->id);
    // ...or reject it instead:
    // $decided = $indicationService->markAsNotSuitable((int)$spaceId, $indication->id);

    echo "Decision recorded. New state: {$decided->state->value}" . PHP_EOL;
} catch (DeliveryIndicationException $e) {
    echo "Error: " . ($e->getLocalizedMessage()?->localize('en-US') ?? $e->getMessage()) . PHP_EOL;
}
