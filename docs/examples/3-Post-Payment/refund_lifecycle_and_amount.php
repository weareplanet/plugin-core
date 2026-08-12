<?php

namespace MyPlugin\ExampleRefundImplementation;

/**
 * Refund Lifecycle and Amount-Based Example
 *
 * This script demonstrates general transaction-level and amount-based refund operations:
 * - Validates refund limits (attempts an excessive refund to trigger a validation error).
 * - Creates a partial refund as a price reduction adjustment (refunding money without returning stock, quantity = 0).
 * - Creates a full refund of the remaining transaction balance.
 * - Fetches a single refund by ID (simulating a webhook processor lookup).
 * - Lists existing refunds and remaining refundable line items.
 *
 * NOTE: For quantity-based partial refunds where physical units of stock are being returned,
 * see refund_quantity_and_stock.php.
 *
 * USAGE:
 * php refund_lifecycle_and_amount.php [transaction_id]
 */

use WeArePlanet\PluginCore\Examples\Common\TransactionIdLoader;
use WeArePlanet\PluginCore\LineItem\LineItemConsistencyService;
use WeArePlanet\PluginCore\Refund\LineItem\RefundLineItem;
use WeArePlanet\PluginCore\Refund\LineItem\RefundLineItemCollection;
use WeArePlanet\PluginCore\Refund\RefundContext;
use WeArePlanet\PluginCore\Refund\RefundService;
use WeArePlanet\PluginCore\Refund\Type;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\RefundGateway;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\TransactionGateway;
use WeArePlanet\PluginCore\Transaction\TransactionService;
use WeArePlanet\PluginCore\Refund\Exception\InvalidRefundException;

// 📖 Concept documentation: See docs/3-Post-Payment/Refund.md

error_reporting(E_ALL & ~E_DEPRECATED);

/** @var array $common */
$common = require __DIR__ . '/../Common/bootstrap.php';

$spaceId = $common['spaceId'];
$sdkProvider = $common['sdkProvider'];
$logger = $common['logger'];
$settings = $common['settings'];
// Load Transaction ID from command line arguments or environment.
try {
    $transactionId = TransactionIdLoader::load($argv);
} catch (\Exception $e) {
    exit($e->getMessage());
}

echo "Operating on Transaction ID: $transactionId\n";

// Setup required services.
$transactionGateway = new TransactionGateway($sdkProvider, $logger, $settings);
$refundGateway = new RefundGateway($sdkProvider, $logger);
$consistency = new LineItemConsistencyService($settings, $logger);

$transactionService = new TransactionService(
    $transactionGateway,
    $consistency,
    $logger
);

$refundService = new RefundService(
    $refundGateway,
    $transactionService,
    $logger
);

// Helper to list refunds
function list_refunds(RefundService $service, int $spaceId, int $transactionId)
{
    echo "\nFetching Refunds for Transaction $transactionId...\n";
    try {
        $refunds = $service->getRefunds($spaceId, $transactionId);
        if (empty($refunds)) {
            echo " > No refunds found.\n";
            return;
        }
        foreach ($refunds as $refund) {
            echo " > Refund ID: {$refund->id}, Amount: {$refund->amount}, State: {$refund->state->value}\n";
            // Failed refunds now carry the gateway's localized failure reason.
            if ($refund->failureReason !== null) {
                echo "   Failure Reason: " . $refund->failureReason->localize('en-US') . "\n";
            }
        }
    } catch (\Exception $e) {
        echo " > Failed to list refunds: " . $e->getMessage() . "\n";
    }
}

// Load the current transaction to check its authorized and refunded amounts.
try {
    $transaction = $transactionService->getTransaction((int)$spaceId, $transactionId);
    echo "Current Authorized Amount: " . $transaction->authorizedAmount . "\n";
    echo "Already Refunded Amount:   " . $transaction->refundedAmount . "\n";

    list_refunds($refundService, (int)$spaceId, $transactionId);

    $remaining = $transaction->authorizedAmount - $transaction->refundedAmount;
    if ($remaining < 0.001) {
        echo "\n⚠️  WARNING: Transaction is already fully refunded.\n";
        echo "    Tests expecting to create new refunds will likely fail.\n";
    }
} catch (\Exception $e) {
    exit("Failed to load transaction: " . $e->getMessage() . "\n");
}

// Test validation error by attempting to refund more than the authorized amount.
echo "\n--- TEST 1: Validation Error (Refund Amount > Authorized) ---\n";
$excessiveAmount = $transaction->authorizedAmount + 10.0;
$context = new RefundContext(
    transactionId: $transactionId,
    amount: $excessiveAmount,
    merchantReference: 'incorrect-amount-test',
    type: Type::MERCHANT_INITIATED_ONLINE
);

try {
    $refundService->createRefund((int)$spaceId, $context);
    echo "FAILED: Expected InvalidRefundException was NOT thrown.\n";
} catch (InvalidRefundException $e) {
    echo "SUCCESS: Caught expected validation error: " . $e->getMessage() . "\n";
} catch (\Exception $e) {
    echo "FAILED: Caught unexpected exception: " . $e->getMessage() . "\n";
}

// Test a partial refund as a price-reduction adjustment (no stock returned).
echo "\n--- TEST 2: Partial Refund (Price Reduction on Swiss Watch) ---\n";

// Find 'sku-123'
$targetSku = 'sku-123';
$targetItem = null;
foreach ($transaction->lineItems as $item) {
    if ($item->sku === $targetSku) {
        $targetItem = $item;
        break;
    }
}

if ($targetItem) {
    echo "Found target item (sku-123) with Quantity: {$targetItem->quantity}\n";

    // We want to refund a fixed amount, say 20.00 total for this line item.
    // Let's assume we want to refund 10.00 per unit.
    $unitReduction = 10.00;
    $totalRefundAmount = $targetItem->quantity * $unitReduction;

    echo "Calculated Refund: $unitReduction per unit * {$targetItem->quantity} units = $totalRefundAmount\n";

    $context = new RefundContext(
        transactionId: $transactionId,
        amount: $totalRefundAmount,
        merchantReference: 'partial-refund-test',
        type: Type::MERCHANT_INITIATED_ONLINE,
        lineItems: new RefundLineItemCollection(
            new RefundLineItem(
                uniqueId: $targetItem->uniqueId,
                returnedQuantity: 0, // Not returning stock, just reducing price.
                unitPriceReduction: $unitReduction, // 10.00 * 2 items = 20.00 total.
            ),
        ),
    );

    try {
        $refund = $refundService->createRefund((int)$spaceId, $context);
        echo "SUCCESS: Partial Refund Created. ID: " . $refund->id . ", State: " . $refund->state->value . "\n";
        $lastRefundId = $refund->id;
        list_refunds($refundService, (int)$spaceId, $transactionId);
    } catch (\Exception $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
    }
} else {
    echo "SKIPPED: Target item 'sku-123' not found in transaction.\n";
}

// Test refunding the entire remaining balance of the transaction.
echo "\n--- TEST 3: Refund Remaining Balance ---\n";
// Reload transaction
$transaction = $transactionService->getTransaction((int)$spaceId, $transactionId);
$remaining = $transaction->authorizedAmount - $transaction->refundedAmount;

if ($remaining > 0.001) {
    echo "Refunding remaining: $remaining\n";
    $context = new RefundContext(
        transactionId: $transactionId,
        amount: $remaining,
        merchantReference: 'final-refund-test',
        type: Type::MERCHANT_INITIATED_ONLINE
    );

    try {
        $refund = $refundService->createRefund((int)$spaceId, $context);
        echo "SUCCESS: Final Refund Created. ID: " . $refund->id . ", State: " . $refund->state->value . "\n";
        $lastRefundId = $refund->id;
    } catch (\Exception $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
    }
} else {
    echo "No remaining amount to refund.\n";
}

// Test fetching a single refund by its ID, as a webhook processor would:
// webhook payloads carry a refund ID but no transaction ID.
echo "\n--- TEST 4: Fetch Refund by ID (Webhook Scenario) ---\n";
if (isset($lastRefundId)) {
    try {
        $refund = $refundService->findById((int)$spaceId, $lastRefundId);
        echo "SUCCESS: Fetched Refund $lastRefundId directly.\n";
        echo " > Transaction ID: {$refund->transactionId} (resolved from the API payload)\n";
        echo " > Amount: {$refund->amount}, State: {$refund->state->value}\n";
        foreach ($refund->lineItems ?? [] as $item) {
            echo " > Refunded item: {$item->name} ({$item->amountIncludingTax})\n";
        }
    } catch (\Exception $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
    }
} else {
    echo "SKIPPED: No refund was created in the previous steps.\n";
}

// Show what is still refundable: the service resolves this from the latest
// successful refund's post-refund cart state (or the original cart if none).
echo "\n--- TEST 5: Remaining Refundable Line Items ---\n";
try {
    $refundable = $refundService->getRefundableLineItems((int)$spaceId, $transactionId);
    if ($refundable->isEmpty()) {
        echo " > Nothing left to refund.\n";
    }
    foreach ($refundable as $item) {
        echo " > Refundable: {$item->name} ({$item->amountIncludingTax}, qty {$item->quantity})\n";
    }
} catch (\Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}
