<?php

namespace MyPlugin\ExampleCheckoutImplementation;

/**
 * Manual Server-Side Confirmation Example
 *
 * IMPORTANT: This flow is NOT needed for the standard integration modes.
 * When using the Payment Page, Iframe, or Lightbox, the transaction is
 * confirmed IMPLICITLY as soon as the customer interacts with the payment
 * widget — your code never calls confirm().
 *
 * Call confirm() explicitly ONLY for manual backend flows:
 *  - MOTO / Admin Panel orders, where a merchant creates the order on
 *    behalf of the customer and no payment widget is ever rendered.
 *  - Server-side race-condition safety: the gateway re-reads the
 *    transaction and confirms against its CURRENT version, so a concurrent
 *    modification fails fast instead of silently proceeding.
 *
 * USAGE:
 * php 5_confirm_manual.php [transaction_id]
 * (Run 3_start_checkout.php first to create a transaction.)
 */

use WeArePlanet\PluginCore\Examples\Common\TransactionIdLoader;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\TransactionGateway;
use WeArePlanet\PluginCore\Transaction\Exception\TransactionException;

// 📖 Concept documentation: See docs/2-Checkout-Flow/Checkout.md

error_reporting(E_ALL & ~E_DEPRECATED);

/** @var array $common */
$common = require __DIR__ . '/../Common/bootstrap.php';

$spaceId = (int)$common['spaceId'];
$sdkProvider = $common['sdkProvider'];
$logger = $common['logger'];
$settings = $common['settings'];

// Load the transaction ID created by 3_start_checkout.php (or pass it as an argument).
try {
    $transactionId = TransactionIdLoader::load($argv);
} catch (\Exception $e) {
    exit($e->getMessage());
}

echo "Manually confirming Transaction ID: $transactionId\n";
echo "(Backend/MOTO flow — no payment widget involved.)\n";

$gateway = new TransactionGateway($sdkProvider, $logger, $settings);

try {
    // The gateway reads the transaction first to obtain its current version,
    // then confirms server-to-server. A concurrent modification between the
    // read and the confirm makes the call fail fast (optimistic locking).
    $transaction = $gateway->confirm($spaceId, $transactionId);

    echo "\n[SUCCESS] Transaction confirmed.\n";
    echo " > ID: {$transaction->id}\n";
    echo " > State: {$transaction->state->value}\n";
} catch (TransactionException $e) {
    // Only pending transactions can be confirmed; already-confirmed or
    // processed transactions will be rejected by the WeArePlanet Portal.
    echo "\n[FAILED] " . $e->getMessage() . "\n";
    echo "Localized: " . $e->getLocalizedMessage()->localize('en-US') . "\n";
    exit(1);
}
