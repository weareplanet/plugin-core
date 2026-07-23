<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV2;

use WeArePlanet\PluginCore\Address\Address;
use WeArePlanet\PluginCore\Customer\CompanyDetails;
use WeArePlanet\PluginCore\Customer\PersonalDetails;
use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\LineItem\LineItemAttribute;
use WeArePlanet\PluginCore\LineItem\LineItemAttributeCollection;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethod;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethodCollection;
use WeArePlanet\PluginCore\PaymentMethod\State as PaymentMethodState;
use WeArePlanet\PluginCore\Sdk\PaymentMethodMapperTrait;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\TransactionMapperTrait;
use WeArePlanet\PluginCore\Settings\IntegrationMode as IntegrationModeEnum;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\PluginCore\Tax\Tax;
use WeArePlanet\PluginCore\Token\State as TokenState;
use WeArePlanet\PluginCore\Token\Token;
use WeArePlanet\PluginCore\Transaction\Exception\TransactionException;
use WeArePlanet\PluginCore\Transaction\PaymentUrl;
use WeArePlanet\PluginCore\Transaction\State as StateEnum;
use WeArePlanet\PluginCore\Transaction\Transaction;
use WeArePlanet\PluginCore\Transaction\TransactionCollection;
use WeArePlanet\PluginCore\Transaction\TransactionContext;
use WeArePlanet\PluginCore\Transaction\TransactionGatewayInterface;
use WeArePlanet\PluginCore\Transaction\TransactionSearchCriteria;
use WeArePlanet\Sdk\ApiException;
use WeArePlanet\Sdk\Model\Address as SdkAddress;
use WeArePlanet\Sdk\Model\AddressCreate as SdkAddressCreate;
use WeArePlanet\Sdk\Model\CreationEntityState as SdkCreationEntityState;
use WeArePlanet\Sdk\Model\LineItem as SdkLineItem;
use WeArePlanet\Sdk\Model\LineItemAttributeCreate as SdkLineItemAttributeCreate;
use WeArePlanet\Sdk\Model\LineItemCreate as SdkLineItemCreate;
use WeArePlanet\Sdk\Model\LineItemType as SdkLineItemType;
use WeArePlanet\Sdk\Model\PaymentMethodConfiguration as SdkPaymentMethodConfiguration;
use WeArePlanet\Sdk\Model\TaxCreate as SdkTaxCreate;
use WeArePlanet\Sdk\Model\Token as SdkToken;
use WeArePlanet\Sdk\Model\Transaction as SdkTransaction;
use WeArePlanet\Sdk\Model\TransactionCreate as SdkTransactionCreate;
use WeArePlanet\Sdk\Model\TransactionPending as SdkTransactionPending;
use WeArePlanet\Sdk\Service\PaymentMethodConfigurationsService as SdkPaymentMethodConfigurationsService;
use WeArePlanet\Sdk\Service\TransactionsService as SdkTransactionsService;

#[LogContext(domain: 'transaction', subdomain: 'checkout')]
class TransactionGateway implements TransactionGatewayInterface
{
    use DomainLoggerTrait;
    use PaymentMethodMapperTrait;
    use TransactionMapperTrait;

    private SdkPaymentMethodConfigurationsService $paymentMethodConfigService;
    private SdkTransactionsService $transactionsService;

    public function __construct(
        private readonly SdkProvider $sdkProvider,
        LoggerInterface $logger,
        private readonly Settings $settings,
    ) {
        $this->initializeLogger($logger);
        $this->transactionsService = $this->sdkProvider->getService(SdkTransactionsService::class);
        $this->paymentMethodConfigService = $this->sdkProvider->getService(SdkPaymentMethodConfigurationsService::class);
    }

    /**
     * Explicitly confirms a transaction on the server side.
     *
     * Standard integration modes confirm implicitly through the payment
     * widget; this call is for manual flows (e.g. MOTO / backend orders).
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID.
     * @return Transaction The confirmed transaction.
     * @throws TransactionException If the confirmation fails.
     */
    public function confirm(int $spaceId, int $transactionId): Transaction
    {
        $this->logger->debug("Gateway: Confirming transaction via server-to-server call.", [
            'transactionId' => $transactionId,
            'spaceId' => $spaceId,
        ]);

        try {
            // Read the current transaction to obtain the version required for
            // optimistic locking on the confirmation call.
            $sdkTransaction = $this->transactionsService->getPaymentTransactionsId($transactionId, $spaceId);

            $sdkTransactionPending = new SdkTransactionPending();
            $sdkTransactionPending->setVersion($sdkTransaction->getVersion());

            // V2: postPaymentTransactionsIdConfirm($id, $space, $transaction_pending)
            $sdkConfirmed = $this->transactionsService->postPaymentTransactionsIdConfirm($transactionId, $spaceId, $sdkTransactionPending);
            $this->logger->debug("Gateway: Transaction confirmed successfully.", [
                'transactionId' => $transactionId,
                'spaceId' => $spaceId,
                'state' => (string)$sdkConfirmed->getState(),
            ]);

            return $this->mapToTransaction($sdkConfirmed);
        } catch (\Throwable $e) {
            $this->logger->error("Gateway: Failed to confirm transaction.", [
                'exception' => $e,
                'transactionId' => $transactionId,
                'spaceId' => $spaceId,
            ]);
            throw new TransactionException(
                "Unable to confirm transaction {$transactionId}: " . $e->getMessage(),
                new LocalizedString('Unable to confirm the transaction.'),
                $e,
            );
        }
    }

    public function create(TransactionContext $context): Transaction
    {
        $this->logger->debug("Gateway: Preparing to CREATE transaction (V2).", [
            'merchantRef' => $context->merchantReference,
            'spaceId' => $context->spaceId,
        ]);

        $sdkBillingAddress = $this->mapAddress($context->billingAddress, $context->personalDetails, $context->companyDetails);
        $sdkShippingAddress = $context->shippingAddress
            ? $this->mapAddress($context->shippingAddress, $context->personalDetails, $context->companyDetails)
            : $sdkBillingAddress;

        $sdkLineItems = array_map([$this, 'mapLineItem'], $context->lineItems->all());

        $sdkTransactionCreate = new SdkTransactionCreate();
        $sdkTransactionCreate->setBillingAddress($sdkBillingAddress);
        $sdkTransactionCreate->setShippingAddress($sdkShippingAddress);
        $sdkTransactionCreate->setLineItems($sdkLineItems);

        $sdkTransactionCreate->setCurrency($context->currencyCode);
        $sdkTransactionCreate->setLanguage($context->language);
        $sdkTransactionCreate->setCustomerEmailAddress($context->personalDetails?->emailAddress);
        $sdkTransactionCreate->setCustomerId($context->customerId);
        $sdkTransactionCreate->setMerchantReference($context->merchantReference);

        if ($context->successUrl !== null) {
            $sdkTransactionCreate->setSuccessUrl($context->successUrl->value);
        }
        if ($context->failedUrl !== null) {
            $sdkTransactionCreate->setFailedUrl($context->failedUrl->value);
        }
        $sdkTransactionCreate->setAutoConfirmationEnabled($context->autoConfirmationEnabled);
        $sdkTransactionCreate->setChargeRetryEnabled($context->chargeRetryEnabled);

        if ($context->spaceViewId !== null) {
            $sdkTransactionCreate->setSpaceViewId($context->spaceViewId);
        }

        if ($context->token) {
            $sdkTransactionCreate->setToken($context->token->id);
        }

        if ($context->tokenizationMode) {
            // Map the PluginCore enum to the SDK's string constant
            $sdkTransactionCreate->setTokenizationMode($context->tokenizationMode->value);
        }

        if ($context->shippingMethod) {
            $sdkTransactionCreate->setShippingMethod($context->shippingMethod);
        }

        try {
            $this->logger->debug("Gateway: Sending CREATE request to SDK (postPaymentTransactions).");
            $sdkTransaction = $this->transactionsService->postPaymentTransactions($context->spaceId, $sdkTransactionCreate);
            $this->logger->debug("Gateway: Transaction created successfully.", ['id' => $sdkTransaction->getId()]);

            return $this->mapToTransaction($sdkTransaction);
        } catch (\Throwable $e) {
            $this->logger->error("Gateway: Failed to create transaction.", [
                'spaceId' => $context->spaceId,
                'exception' => $e,
            ]);
            throw new TransactionException(
                "Unable to create transaction: {$e->getMessage()}",
                new LocalizedString('Unable to create transaction.'),
                $e,
            );
        }
    }

    public function find(int $spaceId, int $transactionId): ?Transaction
    {
        try {
            $sdkTransaction = $this->transactionsService->getPaymentTransactionsId($transactionId, $spaceId, ['billingAddress', 'shippingAddress', 'lineItems', 'token']);
            return $this->mapToTransaction($sdkTransaction);
        } catch (\Throwable $e) {
            if ($e instanceof ApiException && $e->getCode() === 404) {
                $this->logger->debug(
                    'Gateway: Transaction {transactionId} not found in Space {spaceId}.',
                    [
                        'spaceId' => $spaceId,
                        'transactionId' => $transactionId,
                    ],
                );
                return null;
            }

            $this->logger->error(
                'Gateway: Failed to find transaction: {errorMessage}',
                [
                    'errorMessage' => $e->getMessage(),
                    'exception' => $e,
                    'spaceId' => $spaceId,
                    'transactionId' => $transactionId,
                ],
            );
            throw new TransactionException(
                "Failed to find transaction {$transactionId}: " . $e->getMessage(),
                new LocalizedString('Unable to read transaction.'),
                $e,
            );
        }
    }

    public function get(int $spaceId, int $transactionId): Transaction
    {
        $this->logger->debug("Gateway: Reading transaction.", [
            'transactionId' => $transactionId,
            'spaceId' => $spaceId,
        ]);

        try {
            $sdkTransaction = $this->transactionsService->getPaymentTransactionsId($transactionId, $spaceId, ['billingAddress', 'shippingAddress', 'lineItems', 'token']);
            $result = $this->mapToTransaction($sdkTransaction);

            $this->logger->debug("Gateway: Transaction read.", [
                'transactionId' => $transactionId,
                'spaceId' => $spaceId,
                'state' => $result->state->value,
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error("Gateway: Failed to read transaction.", [
                'transactionId' => $transactionId,
                'spaceId' => $spaceId,
                'exception' => $e,
            ]);
            throw new TransactionException(
                "Unable to read transaction {$transactionId} in space {$spaceId}: {$e->getMessage()}",
                new LocalizedString('Unable to read transaction.'),
                $e,
            );
        }
    }

    public function getAvailablePaymentMethods(int $spaceId, int $transactionId): PaymentMethodCollection
    {
        $mode = $this->settings->getIntegrationMode()->value;
        $this->logger->debug("Gateway: Fetching payment methods.", [
            'mode' => $mode,
            'transactionId' => $transactionId,
            'spaceId' => $spaceId,
        ]);

        try {
            // V2: getPaymentTransactionsIdPaymentMethodConfigurations
            $sdkResults = $this->transactionsService->getPaymentTransactionsIdPaymentMethodConfigurations($transactionId, $mode, $spaceId);
            $items = (is_object($sdkResults) && method_exists($sdkResults, 'getData')) ? $sdkResults->getData() : (array)$sdkResults;
            return new PaymentMethodCollection(...array_map([$this, 'mapToPaymentMethod'], $items));
        } catch (\Throwable $e) {
            $this->logger->error("Gateway: Failed to fetch payment methods.", [
                'exception' => $e,
                'transactionId' => $transactionId,
                'spaceId' => $spaceId,
            ]);
            throw new TransactionException(
                "Unable to fetch payment methods for transaction {$transactionId}: " . $e->getMessage(),
                new LocalizedString('Unable to fetch the available payment methods.'),
                $e,
            );
        }
    }

    public function getPaymentMethodConfigurations(int $spaceId): PaymentMethodCollection
    {
        // Search for active payment method configurations using the V2 query syntax.
        $query = "state:ACTIVE";

        try {
            $results = $this->paymentMethodConfigService->getPaymentMethodConfigurationsSearch($spaceId, null, null, null, null, $query);
            $items = (is_object($results) && method_exists($results, 'getData')) ? $results->getData() : (array)$results;
            $this->logger->debug("Gateway: Fetched payment method configurations.", [
                'count' => count($items),
                'spaceId' => $spaceId,
            ]);

            return new PaymentMethodCollection(...array_map([$this, 'mapToPaymentMethod'], $items));
        } catch (\Throwable $e) {
            $this->logger->error("Gateway: Failed to fetch payment method configurations.", [
                'spaceId' => $spaceId,
                'exception' => $e,
            ]);
            throw new TransactionException(
                "Gateway: Failed to fetch payment method configurations: " . $e->getMessage(),
                new LocalizedString('Failed to fetch payment method configurations.'),
                $e,
            );
        }
    }

    public function getPaymentUrl(int $spaceId, int $transactionId): PaymentUrl
    {
        $mode = $this->settings->getIntegrationMode();
        $this->logger->debug("Gateway: Fetching payment URL.", [
            'mode' => $mode->value,
            'transactionId' => $transactionId,
            'spaceId' => $spaceId,
        ]);

        try {
            $url = match ($mode) {
                IntegrationModeEnum::PAYMENT_PAGE => $this->transactionsService
                    ->getPaymentTransactionsIdPaymentPageUrl($transactionId, $spaceId),

                IntegrationModeEnum::IFRAME => $this->transactionsService
                    ->getPaymentTransactionsIdIframeJavascriptUrl($transactionId, $spaceId),

                IntegrationModeEnum::LIGHTBOX => $this->transactionsService
                    ->getPaymentTransactionsIdLightboxJavascriptUrl($transactionId, $spaceId),
            };

            return new PaymentUrl($url);
        } catch (\Throwable $e) {
            $this->logger->error("Gateway: Failed to fetch payment URL.", [
                'exception' => $e,
                'transactionId' => $transactionId,
                'spaceId' => $spaceId,
            ]);
            throw new TransactionException(
                "Unable to fetch payment URL for transaction {$transactionId}: " . $e->getMessage(),
                new LocalizedString('Unable to fetch the payment URL.'),
                $e,
            );
        }
    }

    /**
     * Maps the domain Address plus the customer's identity data onto the flat
     * SDK AddressCreate payload expected by the API.
     *
     * @param Address $source The source address (geographic data).
     * @param PersonalDetails|null $personalDetails The customer's personal identity data.
     * @param CompanyDetails|null $companyDetails The customer's corporate identity data.
     * @return SdkAddressCreate The SDK address.
     */
    private function mapAddress(
        Address $source,
        ?PersonalDetails $personalDetails,
        ?CompanyDetails $companyDetails,
    ): SdkAddressCreate {
        $source->sanitize();

        $sdkAddressCreate = new SdkAddressCreate();
        // The V2 SDK setters reject null, so sparse addresses (e.g. virtual
        // orders) simply omit these fields instead of sending empty strings.
        if ($source->city !== null) {
            $sdkAddressCreate->setCity($source->city);
        }
        if ($source->country !== null) {
            $sdkAddressCreate->setCountry($source->country);
        }

        if ($source->dependentLocality !== null) {
            $sdkAddressCreate->setDependentLocality($source->dependentLocality);
        }
        if ($source->phoneNumber !== null) {
            $sdkAddressCreate->setPhoneNumber($source->phoneNumber);
        }
        if ($source->postalState !== null) {
            $sdkAddressCreate->setPostalState($source->postalState);
        }
        if ($source->postcode !== null) {
            $sdkAddressCreate->setPostcode($source->postcode);
        }
        if ($source->sortingCode !== null) {
            $sdkAddressCreate->setSortingCode($source->sortingCode);
        }
        if ($source->street !== null) {
            $sdkAddressCreate->setStreet($source->street);
        }

        if ($personalDetails?->dateOfBirth !== null) {
            $sdkAddressCreate->setDateOfBirth(\DateTime::createFromImmutable($personalDetails->dateOfBirth));
        }
        if ($personalDetails?->emailAddress !== null) {
            $sdkAddressCreate->setEmailAddress($personalDetails->emailAddress);
        }
        if ($personalDetails?->familyName !== null) {
            $sdkAddressCreate->setFamilyName($personalDetails->familyName);
        }
        if ($personalDetails?->gender !== null) {
            $sdkAddressCreate->setGender($personalDetails->gender->value);
        }
        if ($personalDetails?->givenName !== null) {
            $sdkAddressCreate->setGivenName($personalDetails->givenName);
        }
        if ($personalDetails?->mobilePhoneNumber !== null) {
            $sdkAddressCreate->setMobilePhoneNumber($personalDetails->mobilePhoneNumber);
        }
        if ($personalDetails?->salutation !== null) {
            $sdkAddressCreate->setSalutation($personalDetails->salutation);
        }
        if ($personalDetails?->socialSecurityNumber !== null) {
            $sdkAddressCreate->setSocialSecurityNumber($personalDetails->socialSecurityNumber);
        }

        if ($companyDetails?->commercialRegisterNumber !== null) {
            $sdkAddressCreate->setCommercialRegisterNumber($companyDetails->commercialRegisterNumber);
        }
        if ($companyDetails?->organizationName !== null) {
            $sdkAddressCreate->setOrganizationName($companyDetails->organizationName);
        }
        if ($companyDetails?->salesTaxNumber !== null) {
            $sdkAddressCreate->setSalesTaxNumber($companyDetails->salesTaxNumber);
        }

        return $sdkAddressCreate;
    }

    private function mapLineItem(LineItem $source): SdkLineItemCreate
    {
        $source->sanitize();

        $sdkLineItemCreate = new SdkLineItemCreate();
        $sdkLineItemCreate->setUniqueId($source->uniqueId);
        $sdkLineItemCreate->setSku($source->sku);
        $sdkLineItemCreate->setName($source->name);
        $sdkLineItemCreate->setQuantity($source->quantity);
        $sdkLineItemCreate->setAmountIncludingTax($source->amountIncludingTax);
        $sdkLineItemCreate->setShippingRequired($source->shippingRequired);

        if ($source->attributes !== null && !$source->attributes->isEmpty()) {
            $sdkLineItemCreate->setAttributes($this->mapLineItemAttributes($source->attributes));
        }

        if ($source->discountIncludingTax !== null) {
            $sdkLineItemCreate->setDiscountIncludingTax($source->discountIncludingTax);
        }

        $sdkLineItemCreate->setType(match ($source->type) {
            LineItem::TYPE_DISCOUNT => SdkLineItemType::DISCOUNT,
            LineItem::TYPE_SHIPPING => SdkLineItemType::SHIPPING,
            LineItem::TYPE_FEE => SdkLineItemType::FEE,
            default => SdkLineItemType::PRODUCT,
        });

        if (!empty($source->getTaxes())) {
            $taxes = [];
            foreach ($source->getTaxes() as $taxDto) {
                $taxes[] = $this->mapTax($taxDto);
            }
            $sdkLineItemCreate->setTaxes($taxes);
        }
        return $sdkLineItemCreate;
    }

    /**
     * Maps the domain line item attributes onto the SDK structure expected by
     * `LineItemCreate::setAttributes()` — a map of {@see LineItemAttribute::$id}
     * to {@see SdkLineItemAttributeCreate} with explicit label and value.
     *
     * @param LineItemAttributeCollection $sourceAttributes
     * @return array<string, SdkLineItemAttributeCreate>
     */
    private function mapLineItemAttributes(LineItemAttributeCollection $sourceAttributes): array
    {
        $result = [];
        foreach ($sourceAttributes as $attribute) {
            $sdkAttribute = new SdkLineItemAttributeCreate();
            $sdkAttribute->setLabel($attribute->label);
            $sdkAttribute->setValue($attribute->value);
            $result[$attribute->id] = $sdkAttribute;
        }
        return $result;
    }

    private function mapTax(Tax $source): SdkTaxCreate
    {
        $sdkTaxCreate = new SdkTaxCreate();
        $sdkTaxCreate->setTitle($source->title);
        $sdkTaxCreate->setRate($source->rate);
        return $sdkTaxCreate;
    }


    public function search(int $spaceId, TransactionSearchCriteria $criteria): TransactionCollection
    {
        $this->logger->debug("Gateway: Searching transactions.", ['spaceId' => $spaceId]);

        // V2 Search: Build query string
        $queryParts = [];
        if (!empty($criteria->filters)) {
            foreach ($criteria->filters as $field => $value) {
                // Simple equality
                $queryParts[] = "$field:$value";
            }
        }
        $queryString = implode(" ", $queryParts);

        // Order
        // V2 expects 'order'? format is unclear.
        // Ignoring sort order for now or using null.
        // Criteria has sortField and sortOrder.
        // The V2 'order' parameter is expected to follow the 'field:DIRECTION' format.
        $order = null;
        if ($criteria->sortField !== null) {
            // V2 expects a colon (':') as the separator for sorting fields and their order.
            $order = $criteria->sortField . ":" . ($criteria->sortOrder ?? 'ASC');
        }

        try {
            $results = $this->transactionsService->getPaymentTransactionsSearch($spaceId, null, $criteria->limit, null, $order, $queryString);
            $items = (is_object($results) && method_exists($results, 'getData')) ? $results->getData() : (array)$results;
            return new TransactionCollection(...array_map([$this, 'mapToTransaction'], $items));
        } catch (\Throwable $e) {
            $this->logger->error("Gateway: Failed to search transactions.", [
                'spaceId' => $spaceId,
                'exception' => $e,
            ]);
            throw new TransactionException(
                "Unable to search transactions: {$e->getMessage()}",
                new LocalizedString('Unable to search transactions.'),
                $e,
            );
        }
    }

    public function update(int $transactionId, int $version, TransactionContext $context): Transaction
    {
        $this->logger->debug("Gateway: Preparing to UPDATE transaction (V2).", ['id' => $transactionId]);

        $sdkTransactionPending = new SdkTransactionPending();
        $sdkTransactionPending->setVersion($version);

        // Map the NEW data from the Context
        if ($context->billingAddress) {
            $sdkTransactionPending->setBillingAddress($this->mapAddress($context->billingAddress, $context->personalDetails, $context->companyDetails));
        }
        if ($context->shippingAddress) {
            $sdkTransactionPending->setShippingAddress($this->mapAddress($context->shippingAddress, $context->personalDetails, $context->companyDetails));
        }
        $sdkTransactionPending->setLineItems(array_map([$this, 'mapLineItem'], $context->lineItems->all()));
        $sdkTransactionPending->setCurrency($context->currencyCode);
        $sdkTransactionPending->setLanguage($context->language);
        if ($context->personalDetails?->emailAddress !== null) {
            $sdkTransactionPending->setCustomerEmailAddress($context->personalDetails->emailAddress);
        }
        $sdkTransactionPending->setCustomerId($context->customerId);
        $sdkTransactionPending->setMerchantReference($context->merchantReference);
        if ($context->successUrl !== null) {
            $sdkTransactionPending->setSuccessUrl($context->successUrl->value);
        }
        if ($context->failedUrl !== null) {
            $sdkTransactionPending->setFailedUrl($context->failedUrl->value);
        }

        try {
            $this->logger->debug("Gateway: Sending UPDATE request to SDK (patchPaymentTransactionsId).");
            // V2: patchPaymentTransactionsId
            // Arguments: $id, $space, $transaction_pending
            $sdkTransaction = $this->transactionsService->patchPaymentTransactionsId($transactionId, $context->spaceId, $sdkTransactionPending);
            $this->logger->debug("Gateway: Transaction updated successfully.", ['state' => (string) $sdkTransaction->getState()]);

            return $this->mapToTransaction($sdkTransaction);
        } catch (\Throwable $e) {
            $this->logger->error("Gateway: Failed to update transaction.", [
                'transactionId' => $transactionId,
                'spaceId' => $context->spaceId,
                'exception' => $e,
            ]);
            $exception = new TransactionException(
                "Unable to update transaction {$transactionId} in space {$context->spaceId}: {$e->getMessage()}",
                new LocalizedString('Unable to update transaction.'),
                $e,
            );

            // HTTP 409 means another process updated the transaction concurrently (a version
            // conflict); re-reading and retrying is expected to succeed. Code 0 means no HTTP
            // response was ever received (a connection failure), which is also transient.
            if ($e instanceof ApiException && in_array($e->getCode(), [0, 409], true)) {
                $exception->withRetryable(true);
            }

            throw $exception;
        }
    }
}
