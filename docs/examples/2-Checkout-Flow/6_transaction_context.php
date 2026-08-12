<?php

namespace MyPlugin\ExampleCheckoutImplementation;

/**
 * Transaction Context Example
 *
 * Reads a transaction back and prints the context it was actually processed
 * with: the environment (space view, language) and the payment method
 * (configuration, connector, icon).
 *
 * These are immutable snapshots taken when the transaction ran, which is what
 * an order page or a receipt should display — the merchant may since have
 * renamed the payment method, re-pointed it at another connector or replaced
 * its icon, and the historical record must not change with it.
 *
 * USAGE:
 * php 6_transaction_context.php [transaction_id]
 * (Run 3_start_checkout.php first, then pay via any of the 5_confirm_*.php
 * scripts, so a payment method has actually been selected.)
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

echo "Reading context of Transaction ID: $transactionId\n";

$gateway = new TransactionGateway($sdkProvider, $logger, $settings);

try {
    $transaction = $gateway->find($spaceId, $transactionId);
} catch (TransactionException $e) {
    echo "\n[FAILED] " . $e->getMessage() . "\n";
    echo "Localized: " . $e->getLocalizedMessage()->localize('en-US') . "\n";
    exit(1);
}

if ($transaction === null) {
    echo "No transaction $transactionId exists in space $spaceId.\n";
    exit(1);
}

echo " > State: {$transaction->state->value}\n";

// The environment snapshot is always present on a transaction read back, though
// its individual values are null when the WeArePlanet Portal reported none.
echo "\n[Environment used]\n";
echo " > Space View ID: " . ($transaction->environment?->spaceViewId ?? '(none — space default)') . "\n";
echo " > Language: " . ($transaction->environment?->language ?? '(none)') . "\n";

// The payment method snapshot stays null until the customer has actually chosen
// how to pay, so a pending transaction legitimately has none.
echo "\n[Payment method used]\n";

if ($transaction->paymentMethod === null) {
    echo " > No payment method selected yet — pay via a 5_confirm_*.php script first.\n";
    exit(0);
}

echo " > Payment Method Configuration ID: " . ($transaction->paymentMethod->paymentMethodId ?? '(none)') . "\n";
echo " > Connector ID: " . ($transaction->paymentMethod->connectorId ?? '(none)') . "\n";
echo " > Icon URL: " . ($transaction->paymentMethod->resolvedImageUrl ?? '(none)') . "\n";

echo "\nStore these values with your order: they are what the customer actually\n";
echo "saw at checkout. The merchant's payment method configuration can change\n";
echo "afterward, so re-reading it later would no longer match what happened here.\n";
