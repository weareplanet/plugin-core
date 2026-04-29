<?php

namespace MyPlugin\ExampleRefundImplementation;

/**
 * Refund Example
 *
 * This script demonstrates the Refund functionality:
 * - Validates refund (fails if amount too high).
 * - Creating a Partial Refund.
 * - Creating a Full Refund (of remaining amount).
 *
 * USAGE:
 * php refund.php [transaction_id]
 */

use WeArePlanet\PluginCore\Examples\Common\TransactionIdLoader;
use WeArePlanet\PluginCore\LineItem\LineItemConsistencyService;
use WeArePlanet\PluginCore\Refund\context\RefundContext as ContextRefundContext;
use WeArePlanet\PluginCore\Refund\RefundContext;
use WeArePlanet\PluginCore\Refund\RefundService;
use WeArePlanet\PluginCore\Refund\Type;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\RefundGateway;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\TransactionGateway;
use WeArePlanet\PluginCore\Transaction\TransactionService;
use WeArePlanet\PluginCore\Refund\Exception\InvalidRefundException;

error_reporting(E_ALL & ~E_DEPRECATED);

/** @var array $common */
$common = require __DIR__ . '/../../examples/Common/bootstrap.php';

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
// Note: RefundContext signature check. Original script used named args.
// public function __construct(int $transactionId, float $amount, string $merchantReference, Type $type, array $lineItems = [])
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

// Test a partial refund for a specific line item.
echo "\n--- TEST 2: Partial Refund (On Swiss Watch) ---\n";

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
    // OR roughly 50% of the item value?
    // Requirement: "amount = quantity * unit_reduction".
    // Let's assume we want to refund 10.00 per unit.
    $unitReduction = 10.00;
    $totalRefundAmount = $targetItem->quantity * $unitReduction;

    echo "Calculated Refund: $unitReduction per unit * {$targetItem->quantity} units = $totalRefundAmount\n";

    $context = new RefundContext(
        transactionId: $transactionId,
        amount: $totalRefundAmount,
        merchantReference: 'partial-refund-test',
        type: Type::MERCHANT_INITIATED_ONLINE,
        lineItems: [
            [
                'uniqueId' => $targetItem->uniqueId,
                'quantity' => 0, // 0 quantity usually means "do not return stock" or simple reduction?
                // Original script used 0. 
                // "Refund 20.00 from the Swiss Watch ... without returning the item (qty 0)."
                'amount' => $unitReduction // WebServiceAPIV1 usually expects unit reduction amount here if type relies on it? 
                // Original: "10.00 * 2 items = 20.00 Total Refund". So this is unit amount.
            ]
        ]
    );

    try {
        $refund = $refundService->createRefund((int)$spaceId, $context);
        echo "SUCCESS: Partial Refund Created. ID: " . $refund->id . ", State: " . $refund->state->value . "\n";
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
    } catch (\Exception $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
    }
} else {
    echo "No remaining amount to refund.\n";
}
