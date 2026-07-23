<?php

namespace MyPlugin\ExampleRefundImplementation;

/**
 * Quantity-Based Partial Refund Example (Stock Return)
 *
 * This script demonstrates the SAFE way to calculate a partial refund for a
 * line item when returning physical units of stock (quantity > 0), without
 * introducing floating-point rounding errors that the gateway API will reject.
 *
 * The short version:
 * - Source line items from the refundable state (Invoice-backed), not the
 *   original Transaction cart.
 * - Use LineItem::$unitPriceIncludingTax for the per-unit price.
 * - NEVER derive the unit price via $item->amountIncludingTax / $item->quantity.
 *
 * NOTE: For flat price-reduction adjustments (money-only refunds without returning items to stock),
 * see refund_lifecycle_and_amount.php.
 *
 * USAGE:
 * php refund_quantity_and_stock.php [transaction_id]
 *
 * For a target item with quantity > 1 to exist, run these first:
 *   php ../../Checkout/example/1_start_checkout.php
 *   php ../../Checkout/example/2_modify_cart.php   (bumps the Swiss Watch to qty 2)
 *   php ../../Checkout/example/3_confirm_manual.php
 *   php ../../Completion/example/capture.php
 */

use WeArePlanet\PluginCore\Examples\Common\TransactionIdLoader;
use WeArePlanet\PluginCore\LineItem\LineItemConsistencyService;
use WeArePlanet\PluginCore\Refund\Exception\InvalidRefundException;
use WeArePlanet\PluginCore\Refund\LineItem\RefundLineItem;
use WeArePlanet\PluginCore\Refund\LineItem\RefundLineItemCollection;
use WeArePlanet\PluginCore\Refund\RefundContext;
use WeArePlanet\PluginCore\Refund\RefundService;
use WeArePlanet\PluginCore\Refund\Type;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\RefundGateway;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\TransactionGateway;
use WeArePlanet\PluginCore\Transaction\TransactionService;

error_reporting(E_ALL & ~E_DEPRECATED);

/** @var array $common */
$common = require __DIR__ . '/../../examples/Common/bootstrap.php';

$spaceId = (int)$common['spaceId'];
$sdkProvider = $common['sdkProvider'];
$logger = $common['logger'];
$settings = $common['settings'];

// Load Transaction ID from command line arguments or environment.
try {
    $transactionId = TransactionIdLoader::load($argv);
} catch (\Exception $e) {
    exit($e->getMessage());
}

echo "Calculating a safe partial refund for Transaction ID: $transactionId\n";

// Setup required services — identical DI pattern to refund_lifecycle_and_amount.php.
$transactionGateway = new TransactionGateway($sdkProvider, $logger, $settings);
$refundGateway = new RefundGateway($sdkProvider, $logger);
$consistency = new LineItemConsistencyService($settings, $logger);
$transactionService = new TransactionService($transactionGateway, $consistency, $logger);
$refundService = new RefundService($refundGateway, $transactionService, $logger);

// ==================================================================================
// Get the TRUE, captured line items — not the original Transaction cart.
// ==================================================================================
//
// A Transaction only describes the checkout *promise*. If the order was
// partially captured, the original cart no longer reflects what can actually
// be refunded. RefundService::getRefundableLineItems() resolves the correct
// state for you: it reads the latest SUCCESSFUL refund's post-refund cart
// state (backed by the Invoice), or falls back to the original cart if
// nothing has been refunded yet.
//
// ALTERNATIVE: you can fetch the Invoice directly instead, if you need more
// than just the refundable line items (e.g. its state or paidOn date):
//
//   use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\InvoiceGateway;
//   use WeArePlanet\PluginCore\Transaction\Invoice\InvoiceSearchCriteria;
//
//   $invoiceGateway = new InvoiceGateway($sdkProvider, $logger);
//   $criteria = new InvoiceSearchCriteria(
//       limit: 1,
//       filters: ['completion.lineItemVersion.transaction.id' => $transactionId],
//   );
//   $invoice = $invoiceGateway->search($spaceId, $criteria)->first();
//   $capturedLineItems = $invoice?->lineItems;
//
// See docs/Completion/Invoice.md for the full Invoice documentation.
echo "\n--- Fetching refundable line items ---\n";

try {
    $refundable = $refundService->getRefundableLineItems($spaceId, $transactionId);
} catch (\Exception $e) {
    exit("Failed to fetch refundable line items: " . $e->getMessage() . "\n");
}

if ($refundable->isEmpty()) {
    exit("Nothing left to refund on this transaction.\n");
}

foreach ($refundable as $item) {
    echo " > {$item->name} (sku: {$item->sku}, qty: {$item->quantity}, unit price: {$item->unitPriceIncludingTax})\n";
}

// ==================================================================================
// Select a line item with quantity > 1.
// ==================================================================================
// A partial *unit* return (as opposed to a price adjustment) only makes sense
// when more than one unit was purchased.
echo "\n--- Selecting a target line item (quantity > 1) ---\n";

$targetItem = null;
foreach ($refundable as $item) {
    if ($item->quantity > 1) {
        $targetItem = $item;
        break;
    }
}

if ($targetItem === null) {
    exit(
        "SKIPPED: No refundable line item with quantity > 1 was found.\n" .
        "Run 2_modify_cart.php (which sets the Swiss Watch to qty 2) before capturing, then try again.\n"
    );
}

echo "Target: {$targetItem->name} — quantity {$targetItem->quantity}, unit price {$targetItem->unitPriceIncludingTax}\n";

// ==================================================================================
// Define the partial return — return 1 unit, keep the rest.
// ==================================================================================
$returnedQuantity = 1.0;
$remainingQuantity = $targetItem->quantity - $returnedQuantity;

echo "Returning {$returnedQuantity} unit(s), keeping {$remainingQuantity} unit(s) at full price.\n";

// ==================================================================================
// Define the reduction for the remaining units.
// ==================================================================================
// We are not reducing the price of the remaining units, so the unit price
// reduction on the "remaining quantity" side of the formula is 0:
$unitPriceReduction = 0.0;

// ==================================================================================
// Calculate the total refund amount using unitPriceIncludingTax.
// ==================================================================================
//
// ****************************************************************************
// * IMPORTANT: Always multiply by $unitPriceIncludingTax. NEVER derive the    *
// * per-unit price via division ($item->amountIncludingTax / $item->quantity)*
// * — floating-point division (e.g. 29.99 / 3 = 9.996666...) introduces      *
// * rounding errors that cause the gateway API to reject the refund because  *
// * the reduction amounts don't reconcile exactly with the total.           *
// ****************************************************************************
//
//   Total Reduction = (Quantity Returned * Unit Price) + (Remaining Quantity * Unit Price Reduction)
//   Total Reduction = ($returnedQuantity * $targetItem->unitPriceIncludingTax) + ($remainingQuantity * $unitPriceReduction)
$totalRefundAmount = ($returnedQuantity * $targetItem->unitPriceIncludingTax) + ($remainingQuantity * $unitPriceReduction);

echo "\n--- Calculated Reduction ---\n";
echo "{$returnedQuantity} x {$targetItem->unitPriceIncludingTax} = {$totalRefundAmount}\n";

// ==================================================================================
// Build the RefundContext and execute it via RefundService::createRefund().
// ==================================================================================
// Note: PluginCore never exposes the SDK's internal "RefundCreate" model to
// shop plugins — RefundContext is the public building block; the gateway
// maps it to the SDK object internally, keeping the SDK dependency contained.
//
// We call RefundService::createRefund() here — NOT RefundGateway::refund()
// directly — because the Service layer runs business-rule validation before
// the request ever reaches the API: it checks the requested amount against
// the transaction's remaining authorized amount, and cross-checks that the
// sum of the line item reductions reconciles with the total refund amount.
// Calling the gateway directly would skip these checks and let an
// inconsistent request reach the API unvalidated.
echo "\n--- Executing the Refund ---\n";

$context = new RefundContext(
    transactionId: $transactionId,
    amount: $totalRefundAmount,
    merchantReference: 'gold-standard-partial-refund',
    type: Type::MERCHANT_INITIATED_ONLINE,
    lineItems: new RefundLineItemCollection(
        new RefundLineItem(
            uniqueId: $targetItem->uniqueId,
            returnedQuantity: $returnedQuantity,
            unitPriceReduction: $unitPriceReduction,
        ),
    ),
);

try {
    $refund = $refundService->createRefund($spaceId, $context);
    echo "SUCCESS: Partial refund created. ID: {$refund->id}, State: {$refund->state->value}\n";
} catch (InvalidRefundException $e) {
    echo "FAILED (validation): " . $e->getMessage() . "\n";
} catch (\Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}
