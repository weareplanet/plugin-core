<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV1;

use WeArePlanet\PluginCore\Address\Address;
use WeArePlanet\PluginCore\Customer\CompanyDetails;
use WeArePlanet\PluginCore\Customer\PersonalDetails;
use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\LineItem\LineItemAttribute;
use WeArePlanet\PluginCore\LineItem\LineItemAttributeCollection;
use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethodCollection;
use WeArePlanet\PluginCore\Sdk\PaymentMethodMapperTrait;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\TransactionMapperTrait;
use WeArePlanet\PluginCore\Settings\IntegrationMode as IntegrationModeEnum;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\PluginCore\Tax\Tax;
use WeArePlanet\PluginCore\Transaction\Exception\TransactionException;
use WeArePlanet\PluginCore\Transaction\PaymentUrl;
use WeArePlanet\PluginCore\Transaction\Transaction;
use WeArePlanet\PluginCore\Transaction\TransactionCollection;
use WeArePlanet\PluginCore\Transaction\TransactionContext;
use WeArePlanet\PluginCore\Transaction\TransactionGatewayInterface;
use WeArePlanet\PluginCore\Transaction\TransactionSearchCriteria;
use WeArePlanet\Sdk\ApiException;
use WeArePlanet\Sdk\Model\AddressCreate as SdkAddressCreate;
use WeArePlanet\Sdk\Model\CreationEntityState as SdkCreationEntityState;
use WeArePlanet\Sdk\Model\CriteriaOperator as SdkCriteriaOperator;
use WeArePlanet\Sdk\Model\EntityQuery as SdkEntityQuery;
use WeArePlanet\Sdk\Model\EntityQueryFilter as SdkEntityQueryFilter;
use WeArePlanet\Sdk\Model\EntityQueryFilterType as SdkEntityQueryFilterType;
use WeArePlanet\Sdk\Model\EntityQueryOrderBy as SdkEntityQueryOrderBy;
use WeArePlanet\Sdk\Model\EntityQueryOrderByType as SdkEntityQueryOrderByType;
use WeArePlanet\Sdk\Model\LineItemAttributeCreate as SdkLineItemAttributeCreate;
use WeArePlanet\Sdk\Model\LineItemCreate as SdkLineItemCreate;
use WeArePlanet\Sdk\Model\LineItemType as SdkLineItemType;
use WeArePlanet\Sdk\Model\TaxCreate as SdkTaxCreate;
use WeArePlanet\Sdk\Model\TransactionCreate as SdkTransactionCreate;
use WeArePlanet\Sdk\Model\TransactionPending as SdkTransactionPending;
use WeArePlanet\Sdk\Service\PaymentMethodConfigurationService as SdkPaymentMethodConfigurationService;
use WeArePlanet\Sdk\Service\TransactionIframeService as SdkTransactionIframeService;
use WeArePlanet\Sdk\Service\TransactionLightboxService as SdkTransactionLightboxService;
use WeArePlanet\Sdk\Service\TransactionPaymentPageService as SdkTransactionPaymentPageService;
use WeArePlanet\Sdk\Service\TransactionService as SdkTransactionService;
use WeArePlanet\Sdk\VersioningException;

#[LogContext(domain: 'transaction', subdomain: 'checkout')]
class TransactionGateway implements TransactionGatewayInterface
{
    use DomainLoggerTrait;
    use PaymentMethodMapperTrait;
    use TransactionMapperTrait;

    private SdkPaymentMethodConfigurationService $paymentMethodConfigService;
    private SdkTransactionService $transactionService;

    public function __construct(
        private readonly SdkProvider $sdkProvider,
        LoggerInterface $logger,
        private readonly Settings $settings,
    ) {
        $this->initializeLogger($logger);
        $this->transactionService = $this->sdkProvider->getService(SdkTransactionService::class);
        $this->paymentMethodConfigService = $this->sdkProvider->getService(SdkPaymentMethodConfigurationService::class);
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
            $sdkTransaction = $this->transactionService->read($spaceId, $transactionId);

            $sdkTransactionPending = new SdkTransactionPending();
            $sdkTransactionPending->setId($transactionId);
            $sdkTransactionPending->setVersion($sdkTransaction->getVersion());

            $sdkConfirmed = $this->transactionService->confirm($spaceId, $sdkTransactionPending);
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
            throw SdkProvider::wrapException(
                $e,
                TransactionException::class,
                'confirm',
                ['spaceId' => $spaceId, 'transactionId' => $transactionId],
                'Unable to confirm the transaction.',
            );
        }
    }

    /**
     * Creates a new transaction.
     *
     * @param TransactionContext $context The transaction context.
     * @return Transaction The created transaction.
     * @throws TransactionException If the transaction creation fails.
     */
    public function create(TransactionContext $context): Transaction
    {
        $this->logger->debug("Gateway: Preparing to CREATE transaction.", [
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
        if ($context->invoiceMerchantReference !== null) {
            $sdkTransactionCreate->setInvoiceMerchantReference($context->invoiceMerchantReference);
        }
        if (!empty($context->metaData)) {
            $sdkTransactionCreate->setMetaData($context->metaData);
        }
        if (!empty($context->allowedPaymentMethodConfigurations)) {
            $sdkTransactionCreate->setAllowedPaymentMethodConfigurations($context->allowedPaymentMethodConfigurations);
        }

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

        if ($context->customersPresence !== null) {
            $sdkTransactionCreate->setCustomersPresence($context->customersPresence->value);
        }

        if ($context->deviceSessionIdentifier !== null) {
            $sdkTransactionCreate->setDeviceSessionIdentifier($context->deviceSessionIdentifier);
        }

        if ($context->token) {
            $sdkTransactionCreate->setToken($context->token->id);
        }

        if ($context->tokenizationMode) {
            $sdkTransactionCreate->setTokenizationMode($context->tokenizationMode->value);
        }

        if ($context->shippingMethod) {
            $sdkTransactionCreate->setShippingMethod($context->shippingMethod);
        }

        try {
            $this->logger->debug("Gateway: Sending CREATE request to SDK.");
            $sdkTransaction = $this->transactionService->create($context->spaceId, $sdkTransactionCreate);
            $this->logger->debug("Gateway: Transaction created successfully.", ['id' => $sdkTransaction->getId()]);

            return $this->mapToTransaction($sdkTransaction);
        } catch (\Throwable $e) {
            $this->logger->error("Gateway: Failed to create transaction.", ['exception' => $e]);
            throw SdkProvider::wrapException(
                $e,
                TransactionException::class,
                'create',
                ['spaceId' => $context->spaceId],
                'Unable to create transaction.',
            );
        }
    }

    /**
     * Finds a transaction by ID.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID.
     * @return Transaction|null The transaction, or null if not found.
     */
    public function find(int $spaceId, int $transactionId): ?Transaction
    {
        try {
            $sdkTransaction = $this->transactionService->read($spaceId, $transactionId);
            return $this->mapToTransaction($sdkTransaction);
        } catch (\Throwable $e) {
            if ($e instanceof ApiException && $e->getCode() === 404) {
                $this->logger->debug(
                    'Gateway: Transaction not found.',
                    [
                        'spaceId' => $spaceId,
                        'transactionId' => $transactionId,
                    ],
                );
                return null;
            }

            $this->logger->error(
                'Gateway: Failed to find transaction.',
                [
                    'errorMessage' => $e->getMessage(),
                    'exception' => $e,
                    'spaceId' => $spaceId,
                    'transactionId' => $transactionId,
                ],
            );
            throw SdkProvider::wrapException(
                $e,
                TransactionException::class,
                'read',
                ['spaceId' => $spaceId, 'transactionId' => $transactionId],
                'Unable to read the transaction.',
            );
        }
    }

    /**
     * Gets a transaction by ID and throws if failed.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID.
     * @return Transaction The transaction.
     * @throws \Exception If the transaction cannot be read.
     */
    public function get(int $spaceId, int $transactionId): Transaction
    {
        $this->logger->debug("Gateway: Reading transaction.", [
            'transactionId' => $transactionId,
            'spaceId' => $spaceId,
        ]);

        try {
            $sdkTransaction = $this->transactionService->read($spaceId, $transactionId);
            $result = $this->mapToTransaction($sdkTransaction);

            $this->logger->debug("Gateway: Transaction read.", [
                'transactionId' => $transactionId,
                'spaceId' => $spaceId,
                'state' => $result->state->value,
            ]);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error("Gateway: Failed to read transaction.", ['exception' => $e]);
            throw SdkProvider::wrapException(
                $e,
                TransactionException::class,
                'read',
                ['spaceId' => $spaceId, 'transactionId' => $transactionId],
                'Unable to read the transaction.',
            );
        }
    }

    /**
     * Gets available payment methods for a transaction.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID.
     * @return PaymentMethodCollection The available payment methods.
     */
    public function getAvailablePaymentMethods(int $spaceId, int $transactionId): PaymentMethodCollection
    {
        $mode = $this->settings->getIntegrationMode()->value;
        $this->logger->debug("Gateway: Fetching payment methods.", [
            'mode' => $mode,
            'transactionId' => $transactionId,
            'spaceId' => $spaceId,
        ]);

        try {
            $sdkResults = $this->transactionService->fetchPaymentMethods($spaceId, $transactionId, $mode);
            return new PaymentMethodCollection(...array_map([$this, 'mapToPaymentMethod'], $sdkResults));
        } catch (\Throwable $e) {
            $this->logger->error("Gateway: Failed to fetch payment methods.", [
                'exception' => $e,
                'transactionId' => $transactionId,
                'spaceId' => $spaceId,
            ]);
            throw SdkProvider::wrapException(
                $e,
                TransactionException::class,
                'fetchPaymentMethods',
                ['spaceId' => $spaceId, 'transactionId' => $transactionId],
                'Unable to fetch the available payment methods.',
            );
        }
    }

    /**
     * Gets all active payment method configurations.
     *
     * @param int $spaceId The space ID.
     * @return PaymentMethodCollection The payment method configurations.
     */
    public function getPaymentMethodConfigurations(int $spaceId): PaymentMethodCollection
    {
        $sdkEntityQuery = new SdkEntityQuery();
        $sdkEntityQueryFilter = new SdkEntityQueryFilter();
        $sdkEntityQueryFilter->setType(SdkEntityQueryFilterType::LEAF);
        $sdkEntityQueryFilter->setOperator(SdkCriteriaOperator::EQUALS);
        $sdkEntityQueryFilter->setFieldName('state');
        $sdkEntityQueryFilter->setValue(SdkCreationEntityState::ACTIVE);
        $sdkEntityQuery->setFilter($sdkEntityQueryFilter);

        try {
            $results = $this->paymentMethodConfigService->search($spaceId, $sdkEntityQuery);
            $this->logger->debug("Gateway: Fetched payment method configurations.", [
                'count' => count($results),
                'spaceId' => $spaceId,
            ]);

            return new PaymentMethodCollection(...array_map(
                [$this, 'mapToPaymentMethod'],
                $results,
            ));
        } catch (\Throwable $e) {
            $this->logger->error("Gateway: Failed to fetch payment method configurations.", [
                'exception' => $e,
                'spaceId' => $spaceId,
            ]);
            throw SdkProvider::wrapException(
                $e,
                TransactionException::class,
                'search',
                ['spaceId' => $spaceId],
                'Unable to fetch the payment method configurations.',
            );
        }
    }

    /**
     * Gets the payment URL for a transaction.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID.
     * @return PaymentUrl The payment URL value object.
     */
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
                IntegrationModeEnum::PAYMENT_PAGE => $this->sdkProvider
                    ->getService(SdkTransactionPaymentPageService::class)
                    ->paymentPageUrl($spaceId, $transactionId),

                IntegrationModeEnum::IFRAME => $this->sdkProvider
                    ->getService(SdkTransactionIframeService::class)
                    ->javascriptUrl($spaceId, $transactionId),

                IntegrationModeEnum::LIGHTBOX => $this->sdkProvider
                    ->getService(SdkTransactionLightboxService::class)
                    ->javascriptUrl($spaceId, $transactionId),
            };

            return new PaymentUrl($url);
        } catch (\Throwable $e) {
            $this->logger->error("Gateway: Failed to fetch payment URL.", [
                'exception' => $e,
                'transactionId' => $transactionId,
                'spaceId' => $spaceId,
            ]);
            throw SdkProvider::wrapException(
                $e,
                TransactionException::class,
                'paymentPageUrl',
                ['spaceId' => $spaceId, 'transactionId' => $transactionId],
                'Unable to fetch the payment URL.',
            );
        }
    }

    /**
     * Maps the domain Address plus the customer's identity data onto the flat
     * SDK AddressCreate payload expected by the legacy API.
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

        $sdkAddressCreate->setCity($source->city);
        $sdkAddressCreate->setCountry($source->country);
        $sdkAddressCreate->setDependentLocality($source->dependentLocality);
        $sdkAddressCreate->setPhoneNumber($source->phoneNumber);
        $sdkAddressCreate->setPostalState($source->postalState);
        $sdkAddressCreate->setPostcode($source->postcode);
        $sdkAddressCreate->setSortingCode($source->sortingCode);
        $sdkAddressCreate->setStreet($source->street);

        $sdkAddressCreate->setDateOfBirth($personalDetails?->dateOfBirth ? \DateTime::createFromImmutable($personalDetails->dateOfBirth) : null);
        $sdkAddressCreate->setEmailAddress($personalDetails?->emailAddress);
        $sdkAddressCreate->setFamilyName($personalDetails?->familyName);
        if ($personalDetails?->gender !== null) {
            $sdkAddressCreate->setGender($personalDetails->gender->value);
        }
        $sdkAddressCreate->setGivenName($personalDetails?->givenName);
        $sdkAddressCreate->setMobilePhoneNumber($personalDetails?->mobilePhoneNumber);
        $sdkAddressCreate->setSalutation($personalDetails?->salutation);
        $sdkAddressCreate->setSocialSecurityNumber($personalDetails?->socialSecurityNumber);

        $sdkAddressCreate->setCommercialRegisterNumber($companyDetails?->commercialRegisterNumber);
        $sdkAddressCreate->setOrganizationName($companyDetails?->organizationName);
        $sdkAddressCreate->setSalesTaxNumber($companyDetails?->salesTaxNumber);

        return $sdkAddressCreate;
    }

    /**
     * Maps a domain LineItem to an SDK LineItemCreate.
     *
     * @param LineItem $source The source line item.
     * @return SdkLineItemCreate The SDK line item.
     */
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

    /**
     * Maps a domain Tax to an SDK TaxCreate.
     *
     * @param Tax $source The source tax.
     * @return SdkTaxCreate The SDK tax.
     */
    private function mapTax(Tax $source): SdkTaxCreate
    {
        $sdkTaxCreate = new SdkTaxCreate();
        $sdkTaxCreate->setTitle($source->title);
        $sdkTaxCreate->setRate($source->rate);
        return $sdkTaxCreate;
    }


    /**
     * @inheritDoc
     */
    public function search(int $spaceId, TransactionSearchCriteria $criteria): TransactionCollection
    {
        $this->logger->debug("Gateway: Searching transactions.", ['spaceId' => $spaceId]);

        $query = new SdkEntityQuery();

        if ($criteria->limit !== null) {
            $query->setNumberOfEntities($criteria->limit);
        }

        if ($criteria->sortField !== null) {
            $orderBy = new SdkEntityQueryOrderBy();
            $orderBy->setFieldName($criteria->sortField);
            $orderBy->setSorting(
                strtoupper($criteria->sortOrder) === 'ASC'
                    ? SdkEntityQueryOrderByType::ASC
                    : SdkEntityQueryOrderByType::DESC,
            );
            $query->setOrderBys([$orderBy]);
        }

        if (!empty($criteria->filters)) {
            $filters = [];
            foreach ($criteria->filters as $field => $value) {
                $leaf = new SdkEntityQueryFilter();
                $leaf->setFieldName($field);
                /** @var mixed $value */
                $leaf->setValue($value);
                $leaf->setOperator(SdkCriteriaOperator::EQUALS);
                $leaf->setType(SdkEntityQueryFilterType::LEAF);
                $filters[] = $leaf;
            }

            if (count($filters) === 1) {
                $query->setFilter($filters[0]);
            } elseif (count($filters) > 1) {
                $root = new SdkEntityQueryFilter();
                $root->setType(SdkEntityQueryFilterType::_AND);
                $root->setChildren($filters);
                $query->setFilter($root);
            }
        }

        try {
            $results = $this->transactionService->search($spaceId, $query);
            return new TransactionCollection(...array_map([$this, 'mapToTransaction'], $results));
        } catch (\Throwable $e) {
            $this->logger->error("Gateway: Failed to search transactions.", ['exception' => $e]);
            throw SdkProvider::wrapException(
                $e,
                TransactionException::class,
                'search',
                ['spaceId' => $spaceId],
                'Unable to search transactions.',
            );
        }
    }

    /**
     * Updates an existing transaction.
     *
     * @param int $transactionId The transaction ID.
     * @param int $version The transaction version.
     * @param TransactionContext $context The transaction context.
     * @return Transaction The updated transaction.
     * @throws \Exception If the update fails.
     */
    public function update(int $transactionId, int $version, TransactionContext $context): Transaction
    {
        $this->logger->debug("Gateway: Preparing to UPDATE transaction.", ['id' => $transactionId]);

        $sdkTransactionPending = new SdkTransactionPending();

        $sdkTransactionPending->setId($transactionId);
        $sdkTransactionPending->setVersion($version);

        // Map the NEW data from the Context
        $sdkTransactionPending->setBillingAddress($this->mapAddress($context->billingAddress, $context->personalDetails, $context->companyDetails));
        $sdkTransactionPending->setShippingAddress($context->shippingAddress ? $this->mapAddress($context->shippingAddress, $context->personalDetails, $context->companyDetails) : null);
        $sdkTransactionPending->setLineItems(array_map([$this, 'mapLineItem'], $context->lineItems->all()));
        $sdkTransactionPending->setCurrency($context->currencyCode);
        $sdkTransactionPending->setLanguage($context->language);
        $sdkTransactionPending->setCustomerEmailAddress($context->personalDetails?->emailAddress);
        $sdkTransactionPending->setCustomerId($context->customerId);
        $sdkTransactionPending->setMerchantReference($context->merchantReference);
        if ($context->invoiceMerchantReference !== null) {
            $sdkTransactionPending->setInvoiceMerchantReference($context->invoiceMerchantReference);
        }
        if (!empty($context->metaData)) {
            $sdkTransactionPending->setMetaData($context->metaData);
        }
        if (!empty($context->allowedPaymentMethodConfigurations)) {
            $sdkTransactionPending->setAllowedPaymentMethodConfigurations($context->allowedPaymentMethodConfigurations);
        }
        if ($context->successUrl !== null) {
            $sdkTransactionPending->setSuccessUrl($context->successUrl->value);
        }
        if ($context->failedUrl !== null) {
            $sdkTransactionPending->setFailedUrl($context->failedUrl->value);
        }

        try {
            $this->logger->debug("Gateway: Sending UPDATE request to SDK.");
            $sdkTransaction = $this->transactionService->update($context->spaceId, $sdkTransactionPending);
            $this->logger->debug("Gateway: Transaction updated successfully.", ['state' => (string) $sdkTransaction->getState()]);

            return $this->mapToTransaction($sdkTransaction);
        } catch (\Throwable $e) {
            $this->logger->error("Gateway: Failed to update transaction.", ['exception' => $e]);
            $exception = SdkProvider::wrapException(
                $e,
                TransactionException::class,
                'update',
                ['spaceId' => $context->spaceId, 'transactionId' => $transactionId],
                'Unable to update the transaction.',
            );

            // A version conflict means another process updated the transaction concurrently;
            // re-reading and retrying is expected to succeed. Connection failures are
            // classified centrally in SdkProvider::wrapException().
            if ($e instanceof VersioningException) {
                $exception->withRetryable(true);
            }

            throw $exception;
        }
    }
}
