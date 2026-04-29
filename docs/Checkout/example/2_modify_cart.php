<?php

namespace MyPlugin\ExampleCheckoutImplementation;

use WeArePlanet\PluginCore\Address\Address;
use WeArePlanet\PluginCore\Examples\Common\FilePersistence;
use WeArePlanet\PluginCore\Examples\Common\TransactionIdLoader;
use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\LineItem\LineItemConsistencyService;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethodSorting;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\TransactionGateway;
use WeArePlanet\PluginCore\Tax\Tax;
use WeArePlanet\PluginCore\Transaction\TransactionContext;
use WeArePlanet\PluginCore\Transaction\TransactionService;

error_reporting(E_ALL & ~E_DEPRECATED);

/** @var array $common */
$common = require __DIR__ . '/../../examples/Common/bootstrap.php';

$spaceId = $common['spaceId'];
$sdkProvider = $common['sdkProvider'];
$logger = $common['logger'];
$settings = $common['settings'];
/** @var FilePersistence $persistence */
$persistence = $common['persistence'];

// Initialize core services.
$gateway = new TransactionGateway($sdkProvider, $logger, $settings);
$consistency = new LineItemConsistencyService($settings, $logger);
$service = new TransactionService($gateway, $consistency, $logger);

// Load the existing session to resume the transaction.
// We use the TransactionIdLoader to retrieve the ID from CLI arguments or the session.json file.
try {
    $originalTransactionId = TransactionIdLoader::load($argv);
} catch (\Exception $e) {
    exit("ERROR: No active session. Run '1_start_checkout.php' first.\n");
}

echo "Resuming Checkout for Transaction ID: $originalTransactionId\n";

// Helper to create base context
function create_base_context($spaceId, $txId, $ref): TransactionContext
{
    $context = new TransactionContext();
    $context->spaceId = (int)$spaceId;
    $context->merchantReference = $ref;
    $context->currencyCode = 'CHF';
    $context->language = 'en-US';
    $context->transactionId = $txId;

    $context->customerId = 'guest-123';
    $context->successUrl = 'https://example.com/success';
    $context->failedUrl = 'https://example.com/fail';

    $billing = new Address();
    $billing->givenName = 'John';
    $billing->familyName = 'Doe';
    $billing->street = 'Bahnhofstrasse 1';
    $billing->city = 'Zurich';
    $billing->postcode = '8000';
    $billing->country = 'CH';
    $billing->emailAddress = 'test@example.com';
    $context->billingAddress = $billing;

    return $context;
}

// Helper to fetch and print payment methods
function fetch_and_print_methods(TransactionService $service, int $spaceId, int $txId)
{
    echo " > Fetching available payment methods (Sorted by Name)...\n";
    try {
        $methods = $service->getAvailablePaymentMethods($spaceId, $txId, PaymentMethodSorting::NAME);
        echo "   [Available Payment Methods]:\n";
        foreach ($methods as $method) {
            // Check if title/description is an array (localized) or string
            $title = is_array($method->title) ? ($method->title['en-US'] ?? reset($method->title)) : $method->title;
            echo "   - ID: {$method->id} | {$title}\n";
        }
    } catch (\Exception $e) {
        echo "   [!] Failed to fetch methods: " . $e->getMessage() . "\n";
    }
}

// ==================================================================================
// UPDATE: INCREASE QUANTITY
// ==================================================================================
echo "\n--- [Update 1] Increasing Watch Quantity to 2 ---\n";

$context = create_base_context($spaceId, $originalTransactionId, 'DEMO-UPD-' . rand(1, 20));

$item1 = new LineItem();
$item1->uniqueId = 'sku-123';
$item1->sku = 'sku-123';
$item1->name = 'Swiss Watch';
$item1->quantity = 2; // Changed from 1 to 2
$item1->amountIncludingTax = 300.00; // 150 * 2
$item1->type = LineItem::TYPE_PRODUCT;
$item1->addTax(new Tax('VAT', 7.7));

$context->lineItems = [$item1];
$context->expectedGrandTotal = 300.00;

try {
    $tx = $service->upsert($context, $persistence);
    echo " > Success. Total: 300.00 CHF. Tx ID: {$tx->id}\n";
    fetch_and_print_methods($service, (int)$spaceId, $tx->id);
} catch (\Exception $e) {
    exit(" > Error: " . $e->getMessage() . "\n");
}

echo " > Sometime later...\n";
sleep(2); // Reduced from 5 to 2 for faster demo

// ==================================================================================
// UPDATE: ADD ACCESSORY
// ==================================================================================
echo "\n--- [Update 2] Adding Leather Strap ---\n";

// Re-use items from previous step to simulate cart accumulation
$item2 = new LineItem();
$item2->uniqueId = 'sku-999';
$item2->sku = 'sku-999';
$item2->name = 'Leather Strap';
$item2->quantity = 1;
$item2->amountIncludingTax = 50.00;
$item2->type = LineItem::TYPE_PRODUCT;
$item2->addTax(new Tax('VAT', 7.7));

$context->lineItems = [$item1, $item2]; // Watch(2) + Strap(1)
$context->expectedGrandTotal = 350.00;

try {
    $tx = $service->upsert($context, $persistence);
    echo " > Success. Total: 350.00 CHF. Tx ID: {$tx->id}\n";
    fetch_and_print_methods($service, (int)$spaceId, $tx->id);
} catch (\Exception $e) {
    exit(" > Error: " . $e->getMessage() . "\n");
}

echo " > Sometime later...\n";
sleep(2);

// ==================================================================================
// UPDATE: APPLY DISCOUNT
// ==================================================================================
echo "\n--- [Update 3] Applying 10% Discount ---\n";

$item3 = new LineItem();
$item3->uniqueId = 'discount-summer';
$item3->sku = 'discount-summer';
$item3->name = 'Summer Sale -10%';
$item3->quantity = 1;
$item3->amountIncludingTax = -35.00; // 10% of 350
$item3->type = LineItem::TYPE_DISCOUNT;
$item3->addTax(new Tax('VAT', 7.7));

$context->lineItems = [$item1, $item2, $item3]; // Watch(2) + Strap(1) + Discount
$context->expectedGrandTotal = 315.00;

try {
    $tx = $service->upsert($context, $persistence);
    echo " > Success. Total: 315.00 CHF. Tx ID: {$tx->id}\n";
    fetch_and_print_methods($service, (int)$spaceId, $tx->id);
} catch (\Exception $e) {
    exit(" > Error: " . $e->getMessage() . "\n");
}

// Final Verification
echo "\n---------------------------------------------------\n";
if ($tx->id === $originalTransactionId) {
    echo "VERIFICATION PASSED: Transaction ID remained constant ($originalTransactionId).\n";
} else {
    echo "VERIFICATION FAILED: ID changed from $originalTransactionId to {$tx->id}.\n";
}
echo "NEXT: Confirm the payment by running any of the 3 confirming options. They all start with '3_confirm_*.php'\n";
