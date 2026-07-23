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
 * php refund_lifecycle_and_amount.php [session_file_or_dir] [transaction_id]
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

// Initialize Services via Bootstrap
$common = require __DIR__ . '/../../examples/Common/bootstrap.php';

$spaceId = $common['spaceId'];
$userId = $common['userId'];
$apiSecret = $common['apiSecret'];
$logger = $common['logger'];
$settings = $common['settings'];
$sdkProvider = $common['sdkProvider'];

// Load Transaction ID
try {
    $transactionId = TransactionIdLoader::load($argv);
} catch (\Exception $e) {
    exit(1);
}

echo "Operating on Transaction ID: $transactionId\n";

// Setup Services
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
use WeArePlanet\PluginCore\Refund\LineItem\RefundLineItem;
use WeArePlanet\PluginCore\Refund\LineItem\RefundLineItemCollection;

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
            // Failed refunds now carry the gateway's localized failure reason.
            if ($refund->failureReason !== null) {
                echo "   Failure Reason: " . $refund->failureReason->localize('en-US') . "\n";
            }
        }
    } catch (\Exception $e) {
        echo " > Failed to list refunds: " . $e->getMessage() . "\n";
    }
}

// Load Transaction to see current state
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

// TEST: Validation Error (Over-refund)
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

// TEST: Partial Refund as a price-reduction adjustment (no stock returned)
echo "\n--- TEST 2: Partial Refund (Price Reduction on Swiss Watch) ---\n";

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
        lineItems: new RefundLineItemCollection(
            new RefundLineItem(
                uniqueId: $targetItem->uniqueId,
                returnedQuantity: 0, // We are not returning the item, just reducing price
                unitPriceReduction: $unitReduction, // Reduction PER UNIT
            ),
        ),
    );

    try {
        $refund = $refundService->createRefund((int)$spaceId, $context);
        echo "SUCCESS: Partial Refund Created. ID: " . $refund->id . ", State: " . $refund->state->value . "\n";
        $lastRefundId = $refund->id;
        list_refunds($refundService, $spaceId, $transactionId);
    } catch (\Exception $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
    }
}

// TEST: Full Remaining Refund (if any left)
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
        $lastRefundId = $refund->id;
    } catch (\Exception $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
    }
} else {
    echo "No remaining amount to refund.\n";
}

// TEST: Fetch a single refund by its ID, as a webhook processor would:
// webhook payloads carry a refund ID but no transaction ID.
echo "\n--- TEST 4: Fetch Refund by ID (Webhook Scenario) ---\n";
if (isset($lastRefundId)) {
    try {
        $refund = $refundGateway->findById((int)$spaceId, $lastRefundId);
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
