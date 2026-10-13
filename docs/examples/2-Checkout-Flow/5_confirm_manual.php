<?php

namespace MyPlugin\ExampleCheckoutImplementation;

/**
 * Manual Server-Side Confirmation Example
 *
 * IMPORTANT: This flow is NOT needed for the standard integration modes.
 * When using the Payment Page, Iframe, or Lightbox, the transaction is
 * confirmed IMPLICITLY as soon as the customer interacts with the payment
 * widget — your code never confirms it.
 *
 * Call TransactionService::confirmTransaction() explicitly ONLY when the shop
 * confirms the transaction itself:
 *  - MOTO / Admin Panel orders, where a merchant creates the order on
 *    behalf of the customer and no payment widget is ever rendered.
 *  - Confirming at order placement, once the shop's order exists.
 *
 * confirmTransaction() only confirms. A shop that has to send its final order
 * data first runs upsert() right before and checks that the returned transaction
 * has the ID it holds (see docs/2-Checkout-Flow/Checkout.md).
 *
 * USAGE:
 * php 5_confirm_manual.php [transaction_id]
 * (Run 3_start_checkout.php first to create a transaction.)
 */

use WeArePlanet\PluginCore\Examples\Common\TransactionIdLoader;
use WeArePlanet\PluginCore\LineItem\LineItemConsistencyService;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\TransactionGateway;
use WeArePlanet\PluginCore\Transaction\Exception\TransactionException;
use WeArePlanet\PluginCore\Transaction\Exception\TransactionNotConfirmableException;
use WeArePlanet\PluginCore\Transaction\TransactionService;

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
$consistency = new LineItemConsistencyService($settings, $logger);
$service = new TransactionService($gateway, $consistency, $logger);

try {
    // Confirms the PENDING transaction and returns it, read again afterwards. A transaction that is
    // already CONFIRMED is returned as it is, so repeating the call is safe. Conflicts with other
    // requests and temporary connection errors are retried a few times.
    $transaction = $service->confirmTransaction($spaceId, $transactionId);

    echo "\n[SUCCESS] Transaction confirmed.\n";
    echo " > ID: {$transaction->id}\n";
    echo " > State: {$transaction->state->value}\n";
} catch (TransactionNotConfirmableException $e) {
    // Neither PENDING nor CONFIRMED, for example already failed or voided. A shop would
    // cancel its own order here.
    echo "\n[FAILED] The transaction is {$e->state->value} and cannot be confirmed.\n";
    echo "Localized: " . $e->getLocalizedMessage()->localize('en-US') . "\n";
    exit(1);
} catch (TransactionException $e) {
    echo "\n[FAILED] " . $e->getMessage() . "\n";
    echo "Localized: " . $e->getLocalizedMessage()->localize('en-US') . "\n";
    exit(1);
}
