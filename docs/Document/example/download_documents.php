<?php

namespace WeArePlanet\Example;

/**
 * Document Retrieval Example
 *
 * This script demonstrates how to retrieve:
 * 1. Invoice PDF
 * 2. Packing Slip PDF
 * 3. Refund Credit Note PDF (if a refund exists)
 *
 * USAGE:
 * php download_documents.php [session_file_or_dir] [transaction_id]
 */

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../../examples/Common/bootstrap.php';

use WeArePlanet\PluginCore\Document\DocumentService;
use WeArePlanet\PluginCore\LineItem\LineItemConsistencyService;
use WeArePlanet\PluginCore\Refund\RefundService;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\DocumentGateway;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\RefundGateway;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\TransactionGateway;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\PluginCore\Transaction\TransactionService;
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
$documentGateway = new DocumentGateway($sdkProvider, $logger);
$documentService = new DocumentService($documentGateway);

// Helper to determine download directory
function determineDownloadDirectory(): string
{
    $home = getenv('HOME');
    if (!$home) {
        // Fallback for non-UNIX or if HOME not set
        return getcwd();
    }

    $candidates = [
        $home . '/Downloads',
        $home
    ];

    foreach ($candidates as $dir) {
        if (is_dir($dir) && is_writable($dir)) {
            return $dir;
        }
    }

    echo "WARNING: Could not write to ~/Downloads or ~. Falling back to current directory.\n";
    return getcwd();
}

$downloadDir = determineDownloadDirectory();
echo "Download Directory: " . $downloadDir . "\n";

// Helper to save file
function saveDocument(string $name, string $data, string $mimeType, string $directory): void
{
    $ext = match ($mimeType) {
        'application/pdf' => 'pdf',
        default => 'bin'
    };
    $filename = $directory . DIRECTORY_SEPARATOR . "{$name}.{$ext}";

    if (file_put_contents($filename, $data) === false) {
        echo "ERROR: Failed to write to $filename. Trying current directory...\n";
        $filename = getcwd() . DIRECTORY_SEPARATOR . "{$name}.{$ext}";
        if (file_put_contents($filename, $data) === false) {
            echo "ERROR: Failed to write to current directory as well. Could not save document.\n";
            return;
        }
    }

    echo "Saved $name to: " . $filename . "\n";
}

// 4. Retrieve Invoice
try {
    echo "\n--- Fetching Invoice ---\n";
    $invoice = $documentService->getInvoice((int)$spaceId, (int)$transactionId);
    echo "Title: " . $invoice->title . "\n";
    saveDocument("invoice_{$transactionId}", $invoice->data, $invoice->mimeType, $downloadDir);
} catch (\Exception $e) {
    echo "FAILED to get Invoice: " . $e->getMessage() . "\n";
}

// 5. Retrieve Packing Slip
try {
    echo "\n--- Fetching Packing Slip ---\n";
    $packingSlip = $documentService->getPackingSlip((int)$spaceId, (int)$transactionId);
    echo "Title: " . $packingSlip->title . "\n";
    saveDocument("packing_slip_{$transactionId}", $packingSlip->data, $packingSlip->mimeType, $downloadDir);
} catch (\Exception $e) {
    echo "FAILED to get Packing Slip: " . $e->getMessage() . "\n";
}

// 6. Retrieve Refund Credit Note
// We first need to find if there are any refunds for this transaction.
try {
    echo "\n--- Fetching Refund Credit Note ---\n";

    // Initialize services required for RefundService
    $transactionGateway = new TransactionGateway($sdkProvider, $logger, $settings);
    $consistencyService = new LineItemConsistencyService($settings, $logger);
    $transactionService = new TransactionService($transactionGateway, $consistencyService, $logger);
    $refundGateway = new RefundGateway($sdkProvider, $logger);

    $refundService = new RefundService($refundGateway, $transactionService, $logger);

    // Use PluginCore RefundService to find refunds
    $refunds = $refundService->getRefunds((int)$spaceId, (int)$transactionId);

    if (!empty($refunds) && count($refunds) > 0) {
        $refund = $refunds[0];
        $refundId = $refund->id;
        echo "Found Refund ID: $refundId\n";

        $creditNote = $documentService->getRefundDocument((int)$spaceId, (int)$refundId);
        echo "Title: " . $creditNote->title . "\n";
        saveDocument("refund_note_{$refundId}", $creditNote->data, $creditNote->mimeType, $downloadDir);
    } else {
        echo "No refunds found for this transaction. Skipping Credit Note retrieval.\n";
    }
} catch (\Exception $e) {
    echo "FAILED to get Refund Credit Note: " . $e->getMessage() . "\n";
}
