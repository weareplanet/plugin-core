<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction;

use WeArePlanet\PluginCore\Address\Address;
use WeArePlanet\PluginCore\Customer\CompanyDetails;
use WeArePlanet\PluginCore\Customer\PersonalDetails;
use WeArePlanet\PluginCore\LineItem\LineItemCollection;
use WeArePlanet\PluginCore\SharedKernel\JsonStringableTrait;
use WeArePlanet\PluginCore\SharedKernel\StringSanitizer;
use WeArePlanet\PluginCore\SharedKernel\Url;
use WeArePlanet\PluginCore\Token\Token;
use WeArePlanet\PluginCore\Token\TokenizationMode as TokenizationModeEnum;

/**
 * The standardized input required to create a transaction.
 */
class TransactionContext
{
    use JsonStringableTrait;

    /**
     * Optional list of payment method configuration IDs to restrict the
     * available payment methods to. An empty array means no restriction.
     *
     * @var array<int>
     */
    public array $allowedPaymentMethodConfigurations = [];
    public bool $autoConfirmationEnabled = true;

    // --- Data ---
    public Address $billingAddress;
    public bool $chargeRetryEnabled = true;
    public ?CompanyDetails $companyDetails = null;

    // --- Settings ---
    public string $currencyCode; // ISO 4217 (e.g., 'EUR')
    public string $customerId;
    public ?CustomersPresence $customersPresence = null;

    /**
     * Optional device fingerprint identifier forwarded to the gateway for
     * fraud scoring. Shops that integrate the device-session JS snippet
     * should provide the resulting cookie value here.
     */
    public ?string $deviceSessionIdentifier = null;

    // --- Validation Data ---
    /** @var float The expected final amount (including tax) calculated by the Shop system. */
    public float $expectedGrandTotal;
    public ?Url $failedUrl = null;

    /**
     * Merchant reference for the invoice generated when the transaction
     * completes, as distinct from the transaction's own merchantReference.
     */
    public ?string $invoiceMerchantReference = null;
    public string $language;     // IETF BCP 47 (e.g., 'en-US')

    public LineItemCollection $lineItems;
    public string $merchantReference; // The Order Number (e.g., "10000001")

    /**
     * Arbitrary shop-defined key/value data to attach to the transaction.
     *
     * @var array<string, mixed>
     */
    public array $metaData = [];
    public ?PersonalDetails $personalDetails = null;
    public ?Address $shippingAddress = null;
    public ?string $shippingMethod = null;

    // --- Identity ---
    public int $spaceId;

    // --- Configuration (Optional defaults) ---
    public ?int $spaceViewId = null;

    // --- Routing ---
    public ?Url $successUrl = null;
    /**
     * The token used to create the transaction.
     */
    public ?Token $token = null;
    public ?TokenizationModeEnum $tokenizationMode = null;
    public ?int $transactionId = null; // If updating an existing transaction

    public function __construct()
    {
        $this->lineItems = new LineItemCollection();
    }

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

        // Default language if not present in Transaction
        $context->language = 'en-US';

        // Missing required fields like successUrl/failedUrl need defaults or to be set by caller.
        $context->successUrl = new Url('http://localhost/success');
        $context->failedUrl = new Url('http://localhost/failed');
        $context->expectedGrandTotal = $transaction->authorizedAmount ?? 0.0;

        return $context;
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
}
