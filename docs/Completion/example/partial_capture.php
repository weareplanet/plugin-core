<?php

namespace MyPlugin\ExamplePartialCaptureImplementation;

/**
 * Partial Capture Example
 *
 * This script demonstrates the platform-agnostic capture request path:
 * - Building a LineItemCollection describing what is being captured now.
 * - Wrapping it in a CaptureRequest.
 * - Passing it as the optional third argument to
 *   TransactionCompletionGatewayInterface::capture().
 *
 * Unlike TransactionCompletionService::capture() (which always captures the
 * full remaining authorized amount, since it has no way to pass a
 * CaptureRequest), calling the gateway directly with a CaptureRequest lets
 * you capture only specific line items — e.g. when a shipment fulfills part
 * of an order.
 *
 * USAGE:
 * php partial_capture.php <transaction_id> <line_item_unique_id> <amount> [quantity]
 */

use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\LineItem\LineItemCollection;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\TransactionCompletionGateway;
use WeArePlanet\PluginCore\Transaction\Completion\CaptureRequest;
use WeArePlanet\PluginCore\Transaction\Completion\Exception\CompletionException;

error_reporting(E_ALL & ~E_DEPRECATED);

/** @var array $common */
$common = require __DIR__ . '/../../examples/Common/bootstrap.php';

$spaceId = (int)$common['spaceId'];
$sdkProvider = $common['sdkProvider'];
$logger = $common['logger'];

$transactionId = isset($argv[1]) ? (int)$argv[1] : 0;
$uniqueId = $argv[2] ?? 'sku-123';
$amount = isset($argv[3]) ? (float)$argv[3] : 25.00;
$quantity = isset($argv[4]) ? (float)$argv[4] : 1.0;

if ($transactionId <= 0) {
    exit("USAGE: php partial_capture.php <transaction_id> [line_item_unique_id] [amount] [quantity]\n");
}

echo "Partially capturing Transaction ID: $transactionId\n";
echo "Line item: $uniqueId, quantity: $quantity, amount: $amount\n";

$completionGateway = new TransactionCompletionGateway($sdkProvider, $logger);

// STEP 1: Build a LineItemCollection describing exactly what is being
// captured right now. Only the fields the capture endpoint cares about need
// to be set: uniqueId, quantity, and the amount being captured for it.
$item = new LineItem();
$item->uniqueId = $uniqueId;
$item->quantity = $quantity;
$item->amountIncludingTax = $amount;

$lineItems = new LineItemCollection($item);

// STEP 2: Wrap the collection in a CaptureRequest. isFinal = false signals
// that further captures may follow for the rest of the order; set it to
// true (the default) once you know no more captures will be issued.
$request = new CaptureRequest(
    lineItems: $lineItems,
    isFinal: false,
    merchantReference: 'partial-capture-example',
);

// STEP 3: Submit the request via the gateway's unified capture() method.
try {
    $completion = $completionGateway->capture($spaceId, $transactionId, $request);

    echo "---------------------------------------------------\n";
    echo "PARTIAL CAPTURE SUCCESSFUL\n";
    echo "---------------------------------------------------\n";
    echo "Completion ID: " . $completion->id . "\n";
    echo "New State:     " . $completion->state->value . "\n";
    if ($completion->failureReason !== null) {
        echo "Failure Reason: " . $completion->failureReason->localize('en-US') . "\n";
    }
    echo "---------------------------------------------------\n";
} catch (CompletionException $e) {
    echo "---------------------------------------------------\n";
    echo "PARTIAL CAPTURE FAILED\n";
    echo "---------------------------------------------------\n";
    echo "Reason: " . $e->getMessage() . "\n";
    echo "---------------------------------------------------\n";
    exit(1);
}
