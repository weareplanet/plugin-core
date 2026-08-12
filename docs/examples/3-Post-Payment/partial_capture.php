<?php

namespace MyPlugin\ExamplePartialCaptureImplementation;

/**
 * Partial Capture Example
 *
 * This script demonstrates the platform-agnostic capture request path:
 * - Reading the transaction to see what was actually authorized.
 * - Building a LineItemCollection describing what is being captured now.
 * - Wrapping it in a CaptureRequest.
 * - Passing it as the optional third argument to
 *   TransactionCompletionService::capture().
 *
 * Omitting the CaptureRequest captures the full remaining authorized amount;
 * passing one captures only the line items it lists — e.g. when a shipment
 * fulfills part of an order.
 *
 * The line items are read from the transaction rather than hardcoded, so the
 * script captures whatever the cart actually holds — including anything
 * 4_modify_cart.php added or changed. This mirrors how the refund examples
 * source their items from RefundService::getRefundableLineItems().
 *
 * USAGE:
 * php partial_capture.php <transaction_id> [line_item_unique_id] [amount]
 * (Run 3_start_checkout.php, then a 5_confirm_*.php script, to authorize a
 * transaction first. Run 4_modify_cart.php for a multi-unit cart.)
 */

use WeArePlanet\PluginCore\GlobalData\Currency\CurrencyRoundingService;
use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\LineItem\LineItemCollection;
use WeArePlanet\PluginCore\LineItem\LineItemConsistencyService;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\TransactionCompletionGateway;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\TransactionGateway;
use WeArePlanet\PluginCore\Transaction\Completion\CaptureRequest;
use WeArePlanet\PluginCore\Transaction\Completion\Exception\CompletionException;
use WeArePlanet\PluginCore\Transaction\Completion\TransactionCompletionService;
use WeArePlanet\PluginCore\Transaction\TransactionService;

// 📖 Concept documentation: See docs/3-Post-Payment/Completion.md

error_reporting(E_ALL & ~E_DEPRECATED);

/** @var array $common */
$common = require __DIR__ . '/../Common/bootstrap.php';

$spaceId = (int)$common['spaceId'];
$sdkProvider = $common['sdkProvider'];
$logger = $common['logger'];
$settings = $common['settings'];

$transactionId = isset($argv[1]) ? (int)$argv[1] : 0;
$wantedUniqueId = $argv[2] ?? null;
$explicitAmount = isset($argv[3]) ? (float)$argv[3] : null;

if ($transactionId <= 0) {
    exit("USAGE: php partial_capture.php <transaction_id> [line_item_unique_id] [amount]\n");
}

echo "Partially capturing Transaction ID: $transactionId\n";

// Consumers talk to the domain service, never to the gateway directly.
$transactionService = new TransactionService(
    new TransactionGateway($sdkProvider, $logger, $settings),
    new LineItemConsistencyService($settings, $logger),
    $logger,
);
$completionService = new TransactionCompletionService(
    new TransactionCompletionGateway($sdkProvider, $logger),
    $logger,
);

// ==================================================================================
// STEP 1: Read what was actually authorized.
// ==================================================================================
// The line items come from the transaction so this script stays in step with
// the cart: 4_modify_cart.php changes it (2x Swiss Watch, 1x Leather Strap, a
// discount line), and a hardcoded item would silently ignore all of that.
echo "\n--- Reading the authorized line items ---\n";

try {
    $transaction = $transactionService->getTransaction($spaceId, $transactionId);
} catch (\Exception $e) {
    exit("Failed to read transaction: " . $e->getMessage() . "\n");
}

echo "Transaction state: {$transaction->state->value}\n";

// Ask the service whether a completion is possible rather than comparing states
// here: a capture moves the transaction out of AUTHORIZED, so re-running this
// script against an already-captured transaction is rejected by the API. This
// turns that into a clear message instead of an unhandled API error.
if (!$completionService->canComplete($transaction)) {
    exit(
        "Cannot capture: the transaction is {$transaction->state->value}, not AUTHORIZED.\n"
        . "A capture requires an authorized transaction, and a transaction that has already\n"
        . "been captured has left that state. Authorize a fresh one with 3_start_checkout.php\n"
        . "followed by a 5_confirm_*.php script.\n"
    );
}

if ($transaction->lineItems === []) {
    exit("Transaction $transactionId has no line items to capture.\n");
}

$currency = $transaction->currency ?? 'EUR';

// NOTE: these are the *authorized* line items. Unlike refunds — where
// RefundService::getRefundableLineItems() nets off what was already refunded —
// there is no capture-side equivalent, so this list does not shrink after a
// capture. It reflects what was authorized, not what is still capturable.
foreach ($transaction->lineItems as $item) {
    echo " > {$item->uniqueId}: {$item->name} "
        . "(qty: {$item->quantity}, unit price: {$item->unitPriceIncludingTax}, "
        . "total: {$item->amountIncludingTax})\n";
}

// ==================================================================================
// STEP 2: Pick the line item to capture.
// ==================================================================================
// Discounts and other non-positive lines are skipped: they are not a shipment,
// and capturing one on its own is not meaningful.
echo "\n--- Selecting a line item to capture ---\n";

$targetItem = null;
foreach ($transaction->lineItems as $item) {
    if ($wantedUniqueId !== null && $item->uniqueId !== $wantedUniqueId) {
        continue;
    }

    if ($item->amountIncludingTax > 0 && $item->type !== LineItem::TYPE_DISCOUNT) {
        $targetItem = $item;
        break;
    }
}

if ($targetItem === null) {
    exit(
        $wantedUniqueId !== null
            ? "No capturable line item '{$wantedUniqueId}' on this transaction.\n"
            : "No capturable (positive, non-discount) line item on this transaction.\n"
    );
}

echo "Target: {$targetItem->name} ({$targetItem->uniqueId}) — "
    . "qty {$targetItem->quantity} at {$targetItem->unitPriceIncludingTax} each "
    . "(line total {$targetItem->amountIncludingTax})\n";

// ==================================================================================
// STEP 3: Decide how much of it to capture now.
// ==================================================================================
// A capture may take LESS than was authorized for a line item — the API accepts
// it, and this is exactly what makes a capture "partial". There are two ways to
// be partial, and which one applies depends on the cart:
//
//  - By quantity: ship 1 of the 2 units ordered, and capture that unit's price.
//    Multiplying the unit price keeps the figure exact; deriving it as
//    amountIncludingTax / quantity would introduce float drift (29.99 / 3 =
//    9.996666...), which is why LineItem exposes unitPriceIncludingTax.
//  - By amount: with only one unit there is no quantity to split, so capture a
//    part of its value instead and leave the remainder for a later capture.
//
// An explicit amount can be passed as the third CLI argument to override this.
if ($explicitAmount !== null) {
    $capturedQuantity = $targetItem->quantity;
    $capturedAmount = $explicitAmount;
    $how = 'explicit amount from the command line';
} elseif ($targetItem->quantity > 1) {
    $capturedQuantity = 1.0;
    $capturedAmount = $targetItem->unitPriceIncludingTax;
    $how = 'one unit of several';
} else {
    $capturedQuantity = $targetItem->quantity;
    $capturedAmount = CurrencyRoundingService::round($targetItem->amountIncludingTax / 2, $currency);
    $how = 'half the value of a single unit';
}

echo "Capturing {$capturedAmount} {$currency} ({$how}), quantity {$capturedQuantity}.\n";

// ==================================================================================
// STEP 4: Build the capture line item.
// ==================================================================================
// Only the fields the capture endpoint cares about need to be set: uniqueId,
// quantity, and the amount being captured for it. Nothing else (name, sku,
// taxes...) is transmitted for a capture.
$item = new LineItem();
$item->uniqueId = $targetItem->uniqueId;
$item->quantity = $capturedQuantity;
$item->amountIncludingTax = $capturedAmount;

$lineItems = new LineItemCollection($item);

// ==================================================================================
// STEP 5: Wrap the collection in a CaptureRequest.
// ==================================================================================
// isFinal = false signals that further captures may follow for the rest of the
// order; set it to true (the default) once no more captures will be issued.
// Left false here because this script always captures less than the whole cart.
//
// externalId is the API's idempotency key and is REQUIRED for a partial
// capture. Derive it from something stable on your side — a shipment ID, say —
// so that retrying the same capture after a timeout is recognised as the same
// capture instead of taking the money a second time. Here it is derived from
// the transaction and the line item being captured.
$request = new CaptureRequest(
    lineItems: $lineItems,
    isFinal: false,
    externalId: "capture-{$transactionId}-{$targetItem->uniqueId}",
    merchantReference: 'partial-capture-example',
);

// ==================================================================================
// STEP 6: Submit the request via the service's unified capture() method.
// ==================================================================================
try {
    $completion = $completionService->capture($spaceId, $transactionId, $request);

    echo "---------------------------------------------------\n";
    echo "PARTIAL CAPTURE SUCCESSFUL\n";
    echo "---------------------------------------------------\n";
    echo "Completion ID: " . $completion->id . "\n";
    echo "New State:     " . $completion->state->value . "\n";
    if ($completion->failureReason !== null) {
        echo "Failure Reason: " . $completion->failureReason->localize('en-US') . "\n";
    }
    echo "---------------------------------------------------\n";
    echo "Run fetch_invoice.php {$transactionId} to see the invoice this produced.\n";
} catch (CompletionException $e) {
    echo "---------------------------------------------------\n";
    echo "PARTIAL CAPTURE FAILED\n";
    echo "---------------------------------------------------\n";
    echo "Reason: " . $e->getMessage() . "\n";
    echo "---------------------------------------------------\n";
    exit(1);
}
