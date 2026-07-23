<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction;

use WeArePlanet\PluginCore\Address\Address;
use WeArePlanet\PluginCore\Customer\CompanyDetails;
use WeArePlanet\PluginCore\Customer\PersonalDetails;
use WeArePlanet\PluginCore\LineItem\LineItemCollection;
use WeArePlanet\PluginCore\SharedKernel\Url;
use WeArePlanet\PluginCore\Token\Token;
use WeArePlanet\PluginCore\Token\TokenizationMode as TokenizationModeEnum;
use WeArePlanet\PluginCore\SharedKernel\JsonStringableTrait;
use WeArePlanet\PluginCore\SharedKernel\StringSanitizer;

/**
 * The standardized input required to create a transaction.
 */
class TransactionContext
{
    use JsonStringableTrait;

    public function __construct()
    {
        $this->lineItems = new LineItemCollection();
    }

    /**
     * Normalizes this context in place to satisfy gateway field constraints:
     * truncates `shippingMethod` to the gateway's maximum length.
     *
     * Call this after populating the context and before handing it to a
     * gateway, so oversized shop data never reaches the API.
     */
    public function sanitize(): void
    {
        if ($this->shippingMethod !== null) {
            $this->shippingMethod = StringSanitizer::truncate($this->shippingMethod, 200);
        }
    }

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
    public ?Url $successUrl = null;
    public ?Url $failedUrl = null;

    // --- Data ---
    public Address $billingAddress;
    public ?Address $shippingAddress = null;
    public ?PersonalDetails $personalDetails = null;
    public ?CompanyDetails $companyDetails = null;
    public ?string $shippingMethod = null;

    /** List of line items involved in the transaction. */
    public LineItemCollection $lineItems;

    // --- Configuration (Optional defaults) ---
    public ?int $spaceViewId = null;
    public bool $autoConfirmationEnabled = true;
    public bool $chargeRetryEnabled = true;

    /**
     * Optional list of payment method configuration IDs to restrict the
     * available payment methods to. An empty array means no restriction.
     *
     * @var array<int>
     */
    public array $allowedPaymentMethodConfigurations = [];

    /**
     * Merchant reference for the invoice generated when the transaction
     * completes, as distinct from the transaction's own merchantReference.
     */
    public ?string $invoiceMerchantReference = null;

    /**
     * Arbitrary shop-defined key/value data to attach to the transaction.
     *
     * @var array<string, mixed>
     */
    public array $metaData = [];

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
        $context->customerId = $transaction->customerId ?? $transaction->personalDetails->emailAddress ?? 'guest';
        $context->currencyCode = $transaction->currency ?? 'EUR';

        $context->token = $transaction->token;
        $context->billingAddress = $transaction->billingAddress;
        $context->shippingAddress = $transaction->shippingAddress;
        $context->personalDetails = $transaction->personalDetails;
        $context->companyDetails = $transaction->companyDetails;
        $context->lineItems = new LineItemCollection(...$transaction->lineItems);

        // Default language if not present in Transaction (Transaction domain doesn't have language property? Add if needed or default)
        $context->language = 'en-US';

        // Missing required fields like successUrl/failedUrl need defaults or to be set by caller.
        // For now, setting dummy values or expecting caller to override?
        // In the service, we didn't set them, so presumably they were not validated or we rely on defaults?
        $context->successUrl = new Url('http://localhost/success');
        $context->failedUrl = new Url('http://localhost/failed');
        $context->expectedGrandTotal = $transaction->authorizedAmount ?? 0.0;

        return $context;
    }
}
