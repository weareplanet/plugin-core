<?php

namespace MyPlugin\ExampleRefundImplementation;

/**
 * Refund Example
 * 
 * This script demonstrates the Refund functionality:
 * 1. Validates refund (fails if amount too high).
 * 2. Creating a Partial Refund.
 * 3. Creating a Full Refund (of remaining amount).
 * 
 * USAGE:
 * php refund.php [session_file_or_dir] [transaction_id]
 */

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../../examples/Common/bootstrap.php';

use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\TransactionGateway;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\RefundGateway;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\PluginCore\Transaction\TransactionService;
use WeArePlanet\PluginCore\Refund\RefundService;
use WeArePlanet\PluginCore\Refund\RefundContext;
use WeArePlanet\PluginCore\Refund\Type as TypeEnum;
use WeArePlanet\PluginCore\Refund\Exception\InvalidRefundException;
use WeArePlanet\PluginCore\Examples\Common\TransactionIdLoader;

// 1. Initialize Services via Bootstrap
$common = require __DIR__ . '/../../examples/Common/bootstrap.php';

$spaceId = $common['spaceId'];
$userId = $common['userId'];
$apiSecret = $common['apiSecret'];
$logger = $common['logger'];
$settings = $common['settings'];
$sdkProvider = $common['sdkProvider'];

// 2. Load Transaction ID
try {
    $transactionId = TransactionIdLoader::load($argv);
} catch (\Exception $e) {
    exit(1);
}

echo "Operating on Transaction ID: $transactionId\n";

// 3. Setup Services
$transactionGateway = new TransactionGateway($sdkProvider, $logger, $settings);
$refundGateway = new RefundGateway($sdkProvider, $logger);

// We need TransactionService to inject into RefundService
// TransactionService needs many dependencies, but for RefundService it only uses 'getTransaction'.
// Ideally we mock/stub strictly or use a simpler setup, but here we instantiate the real service stack.
// Note: TransactionService dependencies might need mocking if we don't want to instantiate everything.
// However, in this integration example, let's try to instantiate dependencies if possible.
// Wait, TransactionService depends on TransactionGateway, TransactionCompletionGateway, LineItemConsistencyService.
// We only need TransactionGateway for 'getTransaction' in RefundService context usually (read only).
// But let's check RefundService constructor.
// public function __construct(RefundGatewayInterface, TransactionService, LoggerInterface)

// To avoid instantiating the heavy TransactionService with all its write-dependencies just for reading,
// we might conceptually prefer a TransactionRepository, but for now we follow the existing pattern.
// We'll mock the specific parts we don't need or instantiate nulls if PHP allows/we dare, 
// OR just instantiate the REAL TransactionService if we can cheaply.
// Let's rely on standard instantiation.
use WeArePlanet\PluginCore\LineItem\LineItemConsistencyService;

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

// ... (previous code)

// Helper to list refunds
function list_refunds($service, $spaceId, $transactionId)
{
    echo "\nFetching Refunds for Transaction $transactionId...\n";
    try {
        $refunds = $service->getRefunds((int)$spaceId, $transactionId);
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

// 4. Load Transaction to see current state
try {
    $transaction = $transactionService->getTransaction((int)$spaceId, $transactionId);
    echo "Current Authorized Amount: " . $transaction->authorizedAmount . "\n";
    echo "Already Refunded Amount:   " . $transaction->refundedAmount . "\n";

    list_refunds($refundService, $spaceId, $transactionId);

    if ($transaction->refundedAmount >= $transaction->authorizedAmount - 0.001) { // float epsilon
        echo "\n⚠️  WARNING: Transaction is already fully refunded.\n";
        echo "    Tests expecting to create new refunds (Test 2, Test 3) will likely fail or skip.\n";
        echo "    Please run the Checkout Example to create a fresh transaction.\n";
    }
} catch (\Exception $e) {
    exit("Failed to load transaction: " . $e->getMessage() . "\n");
}

// 5. TEST: Validation Error (Over-refund)
echo "\n--- TEST 1: Validation Error (Refund Amount > Authorized) ---\n";
$excessiveAmount = $transaction->authorizedAmount + 10.0;
$context = new RefundContext(
    transactionId: $transactionId,
    amount: $excessiveAmount,
    merchantReference: 'incorect-amount-test',
    type: TypeEnum::MERCHANT_INITIATED_ONLINE
);

try {
    $refundService->createRefund((int)$spaceId, $context);
    echo "FAILED: Expected InvalidRefundException was NOT thrown.\n";
} catch (InvalidRefundException $e) {
    echo "SUCCESS: Caught expected validation error: " . $e->getMessage() . "\n";
} catch (\Exception $e) {
    echo "FAILED: Caught unexpected exception: " . $e->getMessage() . "\n";
}

// 6. TEST: Partial Refund
echo "\n--- TEST 2: Partial Refund (10.00 per unit for Swiss Watch) ---\n";

// Find the Swiss Watch line item to determine valid refund amount
$targetSku = 'sku-123';
$targetItem = null;
foreach ($transaction->lineItems as $lineItem) {
    if ($lineItem->sku === $targetSku) {
        $targetItem = $lineItem;
        break;
    }
}

if (!$targetItem) {
    echo "SKIPPED: Could not find line item with SKU '$targetSku' to test partial refund.\n";
} else {
    // Determine how many we can refund/reduce
    $qty = $targetItem->quantity;
    echo "Found Swtich Watch with Quantity: $qty\n";

    // We want to reduce the unit price by 10.00.
    // Total Refund Amount = Quantity * UnitReduction
    $unitReduction = 10.00;
    $totalRefundAmount = $qty * $unitReduction;

    echo "Refunding total of $totalRefundAmount ($unitReduction per item * $qty items)...\n";

    $context = new RefundContext(
        transactionId: $transactionId,
        amount: $totalRefundAmount,
        merchantReference: 'partial-refund-test',
        type: TypeEnum::MERCHANT_INITIATED_ONLINE,
        lineItems: [
            [
                'uniqueId' => $targetItem->uniqueId,
                'quantity' => 0, // We are not returning the item, just reducing price
                'amount' => $unitReduction // Reduction PER UNIT
            ]
        ]
    );

    try {
        $refund = $refundService->createRefund((int)$spaceId, $context);
        echo "SUCCESS: Partial Refund Created. ID: " . $refund->id . ", State: " . $refund->state->value . "\n";
        list_refunds($refundService, $spaceId, $transactionId);
    } catch (\Exception $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
    }
}

// 7. TEST: Full Remaining Refund (if any left)
echo "\n--- TEST 3: Refund Remaining Balance ---\n";
// Reload transaction to get updated refundedAmount
$transaction = $transactionService->getTransaction((int)$spaceId, $transactionId);
$remaining = $transaction->authorizedAmount - $transaction->refundedAmount;

if ($remaining > 0) {
    echo "Refunding remaining: $remaining\n";
    $context = new RefundContext(
        transactionId: $transactionId,
        amount: $remaining,
        merchantReference: 'final-refund-test',
        type: TypeEnum::MERCHANT_INITIATED_ONLINE
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
