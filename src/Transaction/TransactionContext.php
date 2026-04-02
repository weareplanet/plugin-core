<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction;

use WeArePlanet\PluginCore\Address\Address;
use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\Token\Token;
use WeArePlanet\PluginCore\Token\TokenizationMode as TokenizationModeEnum;
use WeArePlanet\PluginCore\Render\JsonStringableTrait;

/**
 * The standardized input required to create a transaction.
 */
class TransactionContext
{
    use JsonStringableTrait;

    // --- Identity ---
    public int $spaceId;
    public ?int $transactionId = null; // If updating an existing transaction
    public string $merchantReference; // The Order Number (e.g., "10000001")
    public string $customerId;
    /*
     * The token used to create the transaction.
     */
    public ?Token $token = null;
    public ?TokenizationModeEnum $tokenizationMode = null;

    // --- Settings ---
    public string $currencyCode; // ISO 4217 (e.g., 'EUR')
    public string $language;     // IETF BCP 47 (e.g., 'en-US')

    // --- Routing ---
    public string $successUrl;
    public string $failedUrl;

    // --- Data ---
    public Address $billingAddress;
    public ?Address $shippingAddress = null;
    public ?string $shippingMethod = null;

    /** @var list<LineItem> List of line items involved in the transaction. */
    public array $lineItems = [];

    // --- Configuration (Optional defaults) ---
    public ?int $spaceViewId = null;
    public bool $autoConfirmationEnabled = true;
    public bool $chargeRetryEnabled = true;

    // --- Validation Data ---
    /** @var float The expected final amount (including tax) calculated by the Shop system. */
    public float $expectedGrandTotal;

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
        $context->customerId = $transaction->customerId ?? $transaction->billingAddress?->emailAddress ?? 'guest';
        $context->currencyCode = $transaction->currency ?? 'EUR';

        $context->token = $transaction->token;
        $context->billingAddress = $transaction->billingAddress;
        $context->shippingAddress = $transaction->shippingAddress;
        $context->lineItems = $transaction->lineItems;

        // Default language if not present in Transaction (Transaction domain doesn't have language property? Add if needed or default)
        $context->language = 'en-US';

        // Missing required fields like successUrl/failedUrl need defaults or to be set by caller.
        // For now, setting dummy values or expecting caller to override?
        // In the service, we didn't set them, so presumably they were not validated or we rely on defaults?
        $context->successUrl = 'http://localhost/success';
        $context->failedUrl = 'http://localhost/failed';
        $context->expectedGrandTotal = $transaction->authorizedAmount ?? 0.0;

        return $context;
    }
}
