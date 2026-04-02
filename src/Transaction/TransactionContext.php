<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction;

use WeArePlanet\PluginCore\Address\Address;
use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\Render\JsonStringableTrait;
use WeArePlanet\PluginCore\Token\Token;
use WeArePlanet\PluginCore\Token\TokenizationMode as TokenizationModeEnum;

/**
 * The standardized input required to create a transaction.
 */
class TransactionContext
{
    use JsonStringableTrait;
    public bool $autoConfirmationEnabled = true;

    // --- Data ---
    public Address $billingAddress;
    public bool $chargeRetryEnabled = true;

    // --- Settings ---
    public string $currencyCode; // ISO 4217 (e.g., 'EUR')
    public string $customerId;

    // --- Validation Data ---
    /** @var float The expected final amount (including tax) calculated by the Shop system. */
    public float $expectedGrandTotal;
    public string $failedUrl;
    public string $language;     // IETF BCP 47 (e.g., 'en-US')

    /** @var list<LineItem> */
    public array $lineItems = [];
    public string $merchantReference; // The Order Number (e.g., "10000001")
    public ?Address $shippingAddress = null;
    public ?string $shippingMethod = null;

    // --- Identity ---
    public int $spaceId;

    // --- Configuration (Optional defaults) ---
    public ?int $spaceViewId = null;

    // --- Routing ---
    public string $successUrl;
    /**
     * The token used to create the transaction.
     */
    public ?Token $token = null;
    public ?TokenizationModeEnum $tokenizationMode = null;
    public ?int $transactionId = null; // If updating an existing transaction

    /**
     * Creates a TransactionContext from an existing Transaction (for recurring payments).
     *
     * @param Transaction $transaction
     * @return self
     */
    public static function fromTransaction(Transaction $transaction): self
    {
        $context = new self();
        $context->spaceId = $transaction->spaceId;
        // Append suffix to merchant reference
        $context->merchantReference = ($transaction->merchantReference ?? uniqid('rec_')) . '_R';

        // Fallback for customer ID and currency if missing (though they should be present now)
        $context->customerId = $transaction->customerId ?? $transaction->billingAddress->emailAddress ?? 'guest';
        $context->currencyCode = $transaction->currency ?? 'EUR';

        $context->token = $transaction->token;
        $context->billingAddress = $transaction->billingAddress;
        $context->shippingAddress = $transaction->shippingAddress;
        $context->lineItems = $transaction->lineItems;

        // Default language if not present in Transaction
        $context->language = 'en-US';

        // Missing required fields like successUrl/failedUrl need defaults or to be set by caller.
        $context->successUrl = 'http://localhost/success';
        $context->failedUrl = 'http://localhost/failed';
        $context->expectedGrandTotal = $transaction->authorizedAmount ?? 0.0;

        return $context;
    }
}
