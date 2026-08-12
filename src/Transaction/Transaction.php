<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction;

use WeArePlanet\PluginCore\Address\Address;
use WeArePlanet\PluginCore\Customer\CompanyDetails;
use WeArePlanet\PluginCore\Customer\PersonalDetails;
use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\SharedKernel\JsonStringableTrait;
use WeArePlanet\PluginCore\Token\Token;

/**
 * Domain object representing a Transaction.
 */
class Transaction
{
    use JsonStringableTrait;

    /**
     * @var float|null The authorized amount (Validation).
     */
    public ?float $authorizedAmount = null;

    /**
     * @var \DateTimeImmutable|null The date/time when the transaction was authorized.
     */
    public ?\DateTimeImmutable $authorizedOn = null;

    /**
     * @var Address|null The billing address.
     */
    public ?Address $billingAddress = null;

    /**
     * @var CompanyDetails|null The customer's corporate identity data.
     */
    public ?CompanyDetails $companyDetails = null;

    /**
     * @var \DateTimeImmutable|null The date/time when the transaction was completed.
     */
    public ?\DateTimeImmutable $completedOn = null;

    /**
     * @var \DateTimeImmutable|null The date/time when the transaction was created.
     */
    public ?\DateTimeImmutable $createdOn = null;

    /**
     * @var string|null The currency code.
     */
    public ?string $currency = null;

    /**
     * @var string|null The customer ID.
     */
    public ?string $customerId = null;

    /**
     * @var TransactionEnvironment|null The immutable snapshot of the environment this
     * transaction was processed in (space view and language), as it was at processing
     * time — not the shop's current settings.
     */
    public ?TransactionEnvironment $environment = null;

    /**
     * @var \DateTimeImmutable|null The date/time when the transaction failed.
     */
    public ?\DateTimeImmutable $failedOn = null;

    /**
     * @var LocalizedString|null The localized failure reason from the API.
     */
    public ?LocalizedString $failureReason = null;

    /**
     * @var int The transaction ID.
     */
    public int $id;

    /**
     * @var list<LineItem> The line items (Validation).
     */
    public array $lineItems = [];

    /**
     * @var string|null The merchant reference.
     */
    public ?string $merchantReference = null;

    /**
     * @var TransactionPaymentMethod|null The immutable snapshot of the payment method and
     * connector this transaction was processed with, as they were at processing time — not
     * the merchant's current payment method configuration. Null when the API reported no
     * payment connector configuration, e.g. before a payment method has been selected.
     */
    public ?TransactionPaymentMethod $paymentMethod = null;

    /**
     * @var PersonalDetails|null The customer's personal identity data.
     */
    public ?PersonalDetails $personalDetails = null;

    /**
     * @var \DateTimeImmutable|null The date/time when the transaction started processing.
     */
    public ?\DateTimeImmutable $processingOn = null;

    /**
     * @var float|null The amount already refunded (Validation).
     */
    public ?float $refundedAmount = null;

    /**
     * @var Address|null The shipping address.
     */
    public ?Address $shippingAddress = null;

    /**
     * @var int The space ID.
     */
    public int $spaceId;

    /**
     * @var State The strict state enum.
     */
    public State $state;

    /**
     * @var Token|null The token used for the transaction.
     */
    public ?Token $token = null;

    /**
     * @var LocalizedString|null The localized user-facing failure message.
     */
    public ?LocalizedString $userFailureMessage = null;

    /**
     * @var int|null The version number required for optimistic locking (Read-Modify-Write). Nullable for newly created, unsaved transactions.
     */
    public ?int $version = null;
}
