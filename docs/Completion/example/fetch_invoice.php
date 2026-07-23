<?php

namespace MyPlugin\ExampleInvoiceImplementation;

/**
 * Transaction Invoice Example
 *
 * This script demonstrates the read-only Invoice gateway:
 * - Searching for the invoice belonging to a transaction.
 * - Reading a specific invoice via get() / find().
 * - Iterating the invoiced line items (the "captured reality").
 *
 * USAGE:
 * php fetch_invoice.php [transaction_id]
 */

use WeArePlanet\PluginCore\Examples\Common\TransactionIdLoader;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\InvoiceGateway;
use WeArePlanet\PluginCore\Transaction\Invoice\InvoiceSearchCriteria;

error_reporting(E_ALL & ~E_DEPRECATED);

/** @var array $common */
$common = require __DIR__ . '/../../examples/Common/bootstrap.php';

$spaceId = (int)$common['spaceId'];
$sdkProvider = $common['sdkProvider'];
$logger = $common['logger'];

// Load Transaction ID from command line arguments or the session file.
try {
    $transactionId = TransactionIdLoader::load($argv);
} catch (\Exception $e) {
    exit($e->getMessage());
}

echo "Fetching invoice for Transaction ID: $transactionId\n";

$gateway = new InvoiceGateway($sdkProvider, $logger);

// SEARCH: the invoice is linked to its transaction through the completion.
$criteria = new InvoiceSearchCriteria(
    limit: 1,
    filters: ['completion.lineItemVersion.transaction.id' => $transactionId],
);

try {
    $invoice = $gateway->search($spaceId, $criteria)->first();
} catch (\Exception $e) {
    exit("Search failed: " . $e->getMessage() . "\n");
}

if ($invoice === null) {
    exit("No invoice found for transaction $transactionId (was it completed/captured?).\n");
}

echo "\n[Invoice]\n";
echo " > ID: {$invoice->id}\n";
echo " > Transaction: " . ($invoice->linkedTransactionId ?? 'n/a') . "\n";
echo " > State: {$invoice->state->value}\n";
echo " > Amount: {$invoice->amount} (tax: {$invoice->taxAmount})\n";
echo " > Outstanding: " . ($invoice->outstandingAmount ?? 'n/a') . "\n";
echo " > Paid on: " . ($invoice->paidOn?->format(\DATE_ATOM) ?? 'not paid yet') . "\n";

// The invoiced line items are the captured reality — the correct basis for
// calculating refundable line items.
echo "\n[Invoiced Line Items]\n";
foreach ($invoice->lineItems as $item) {
    echo " > {$item->name} x{$item->quantity} = {$item->amountIncludingTax}\n";
}

// GET: returns the invoice or throws an InvoiceException.
$reloaded = $gateway->get($spaceId, $invoice->id);
echo "\nRe-fetched invoice {$reloaded->id} via get().\n";

// FIND: returns null instead of throwing when the invoice does not exist.
$missing = $gateway->find($spaceId, 999999999);
echo "find() for an unknown ID returned: " . ($missing === null ? 'null' : 'an invoice') . "\n";
