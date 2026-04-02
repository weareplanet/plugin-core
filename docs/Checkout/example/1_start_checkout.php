<?php

namespace MyPlugin\ExampleCheckoutImplementation;

error_reporting(E_ALL & ~E_DEPRECATED);


use WeArePlanet\PluginCore\Address\Address;
use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\LineItem\LineItemConsistencyService;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\SdkV2\TransactionGateway;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\PluginCore\Tax\Tax;
use WeArePlanet\PluginCore\Token\TokenizationMode as TokenizationModeEnum;
use WeArePlanet\PluginCore\Transaction\TransactionContext;
use WeArePlanet\PluginCore\Transaction\TransactionService;
use WeArePlanet\PluginCore\Examples\Common\FilePersistence;

// 1. Initialize Services via Bootstrap
$common = require __DIR__ . '/../../examples/Common/bootstrap.php';

$spaceId = $common['spaceId'];
$userId = $common['userId'];
$apiSecret = $common['apiSecret'];
$logger = $common['logger'];
$settings = $common['settings'];
$sdkProvider = $common['sdkProvider'];

// 2. Additional Services
// FilePersistence is now in Common, but we might want to use a local session file
// The unified FilePersistence defaults to getcwd() . '/session.json' which should work 
// if run from the example directory.
$persistence = new FilePersistence(__DIR__ . '/session.json');

$gateway = new TransactionGateway($sdkProvider, $logger, $settings);

// ✅ FIX: Inject Settings and Logger into Consistency Service
$consistency = new LineItemConsistencyService($settings, $logger);

$service = new TransactionService($gateway, $consistency, $logger);

// 3. Initialize Session (Clean Slate)
$sessionFile = __DIR__ . '/session.json';
if (file_exists($sessionFile)) {
    unlink($sessionFile);
    echo "Refreshed Session (Deleted old session.json)\n";
}

// 4. Build Initial Cart
echo "Building Cart (1x Swiss Watch)...\n";

$context = new TransactionContext();
$context->spaceId = (int)$spaceId;
$context->merchantReference = 'DEMO-' . uniqid();
$context->currencyCode = 'CHF';
$context->language = 'en-US';
$context->customerId = 'guest-123';
$context->transactionId = $persistence->getTransactionId();
$context->successUrl = 'https://example.com/success';
$context->failedUrl = 'https://example.com/fail';

// Enable tokenization so the API creates a token with payment credentials
// when the transaction completes. This is required for recurring payments (MIT).
$context->tokenizationMode = TokenizationModeEnum::FORCE_CREATION;

$billing = new Address();
$billing->givenName = 'John';
$billing->familyName = 'Doe';
$billing->street = 'Bahnhofstrasse 1';
$billing->city = 'Zurich';
$billing->postcode = '8000';
$billing->country = 'CH';
$billing->emailAddress = 'test@example.com';
$context->billingAddress = $billing;

$item = new LineItem();
$item->uniqueId = 'sku-123';
$item->sku = 'sku-123';
$item->name = 'Swiss Watch';
$item->quantity = 1;
$item->amountIncludingTax = 150.00;
$item->type = LineItem::TYPE_PRODUCT;
$item->addTax(new Tax('VAT', 7.7));
$context->lineItems = [$item];
$context->expectedGrandTotal = 150.00;

// 5. Execute Upsert
echo "Sending to WeArePlanet...\n";

try {
    $transaction = $service->upsert($context, $persistence);

    echo "\n[SUCCESS] Transaction Created: " . $transaction->id . "\n";
    echo "State: " . $transaction->state->value . "\n";
    echo "NEXT: Run '2_modify_cart.php'\n";
} catch (\Exception $e) {
    echo "\n[ERROR] Transaction Creation Failed.\n";
    echo "Reason: " . $e->getMessage() . "\n";
    exit(1);
}
