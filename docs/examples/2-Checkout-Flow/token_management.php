<?php

namespace MyPlugin\ExampleTokenImplementation;

/**
 * Token Management Example
 *
 * This script demonstrates reading a customer's saved payment credentials:
 * - Creating a token from a completed transaction.
 * - Reading the active token version and the configuration it is bound to.
 * - Resolving that version back to the merchant's configured payment method.
 *
 * NOTE: A token only exists if the original transaction set a TokenizationMode
 * at checkout. See 3_start_checkout.php.
 *
 * USAGE:
 * php token_management.php [transaction_id]
 */

use WeArePlanet\PluginCore\Examples\Common\TransactionIdLoader;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\TokenGateway;
use WeArePlanet\PluginCore\Token\Exception\TokenException;
use WeArePlanet\PluginCore\Token\TokenService;

// 📖 Concept documentation: See docs/2-Checkout-Flow/Token.md

error_reporting(E_ALL & ~E_DEPRECATED);

/** @var array $common */
$common = require __DIR__ . '/../Common/bootstrap.php';

$spaceId = $common['spaceId'];
$sdkProvider = $common['sdkProvider'];
$logger = $common['logger'];

try {
    $transactionId = TransactionIdLoader::load($argv);
} catch (\Exception $e) {
    exit($e->getMessage());
}

$tokenService = new TokenService(new TokenGateway($sdkProvider, $logger), $logger);

try {
    // Only succeeds when the transaction was created with a TokenizationMode.
    $token = $tokenService->createTokenForTransaction((int)$spaceId, $transactionId);

    echo "Token {$token->id}: state {$token->state->value}" . PHP_EOL;
    echo "  chargeable: " . ($token->isChargeable() ? 'yes' : 'no') . PHP_EOL;
    // customerId is your shop's own reference and is the key to filter a customer's
    // saved methods by; customerIdentifier is a display label and is not unique.
    echo "  customerId: " . ($token->customerId ?? '(none)') . PHP_EOL;
    echo "  customerIdentifier: " . ($token->customerIdentifier ?? '(none)') . PHP_EOL;

    // The version currently in force — null when the token has none.
    $version = $tokenService->getActiveTokenVersion((int)$spaceId, $token->id);

    if ($version === null) {
        echo "No active version for this token." . PHP_EOL;
        exit(0);
    }

    echo PHP_EOL . "Active version {$version->id} ({$version->name})" . PHP_EOL;
    // Two pairs of references: the global *types*, and the merchant's *configuration*.
    echo "  paymentMethodId (type):                  " . ($version->paymentMethodId ?? '(none)') . PHP_EOL;
    echo "  paymentMethodConfigurationId (config):   " . ($version->paymentMethodConfigurationId ?? '(none)') . PHP_EOL;
    echo "  connectorId (type):                      " . ($version->connectorId ?? '(none)') . PHP_EOL;
    echo "  connectorConfigurationId (config):       " . ($version->connectorConfigurationId ?? '(none)') . PHP_EOL;

    echo PHP_EOL . "To render a saved-card label, match PaymentMethod::\$id against" . PHP_EOL;
    echo "paymentMethodConfigurationId — never against paymentMethodId." . PHP_EOL;
} catch (TokenException $e) {
    echo "Error: " . ($e->getLocalizedMessage()?->localize('en-US') ?? $e->getMessage()) . PHP_EOL;
}
