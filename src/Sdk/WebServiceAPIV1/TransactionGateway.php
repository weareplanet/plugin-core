<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV1;

use WeArePlanet\PluginCore\Address\Address;
use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethod;
use WeArePlanet\PluginCore\PaymentMethod\State as PaymentMethodState;
use WeArePlanet\PluginCore\PaymentMethodConfiguration\PaymentMethodConfiguration;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\TransactionMapperTrait;
use WeArePlanet\PluginCore\Settings\IntegrationMode as IntegrationModeEnum;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\PluginCore\Tax\Tax;
use WeArePlanet\PluginCore\Transaction\Transaction;
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
use WeArePlanet\Sdk\Model\LineItemCreate as SdkLineItemCreate;
use WeArePlanet\Sdk\Model\LineItemType as SdkLineItemType;
use WeArePlanet\Sdk\Model\PaymentMethodConfiguration as SdkPaymentMethodConfiguration;
use WeArePlanet\Sdk\Model\TaxCreate as SdkTaxCreate;
use WeArePlanet\Sdk\Model\TransactionCreate as SdkTransactionCreate;
use WeArePlanet\Sdk\Model\TransactionPending as SdkTransactionPending;
use WeArePlanet\Sdk\Service\PaymentMethodConfigurationService as SdkPaymentMethodConfigurationService;
use WeArePlanet\Sdk\Service\TransactionIframeService as SdkTransactionIframeService;
use WeArePlanet\Sdk\Service\TransactionLightboxService as SdkTransactionLightboxService;
use WeArePlanet\Sdk\Service\TransactionPaymentPageService as SdkTransactionPaymentPageService;
use WeArePlanet\Sdk\Service\TransactionService as SdkTransactionService;

class TransactionGateway implements TransactionGatewayInterface
{
    use TransactionMapperTrait;

    private SdkPaymentMethodConfigurationService $paymentMethodConfigService;
    private SdkTransactionService $transactionService;

    public function __construct(
        private readonly SdkProvider $sdkProvider,
        private readonly LoggerInterface $logger,
        private readonly Settings $settings,
    ) {
        $this->transactionService = $this->sdkProvider->getService(SdkTransactionService::class);
        $this->paymentMethodConfigService = $this->sdkProvider->getService(SdkPaymentMethodConfigurationService::class);
    }

    /**
     * Creates a new transaction.
     *
     * @param TransactionContext $context The transaction context.
     * @return Transaction The created transaction.
     * @throws \Exception If the transaction creation fails.
     */
    public function create(TransactionContext $context): Transaction
    {
        $this->logger->debug("Gateway: Preparing to CREATE transaction.", [
            'merchantRef' => $context->merchantReference,
            'spaceId' => $context->spaceId,
        ]);

        $sdkBillingAddress = $this->mapAddress($context->billingAddress);
        $sdkShippingAddress = $context->shippingAddress
            ? $this->mapAddress($context->shippingAddress)
            : $sdkBillingAddress;

        $sdkLineItems = array_map([$this, 'mapLineItem'], $context->lineItems);

        $sdkTransactionCreate = new SdkTransactionCreate();
        $sdkTransactionCreate->setBillingAddress($sdkBillingAddress);
        $sdkTransactionCreate->setShippingAddress($sdkShippingAddress);
        $sdkTransactionCreate->setLineItems($sdkLineItems);

        $sdkTransactionCreate->setCurrency($context->currencyCode);
        $sdkTransactionCreate->setLanguage($context->language);
        $sdkTransactionCreate->setCustomerEmailAddress($context->billingAddress->emailAddress);
        $sdkTransactionCreate->setCustomerId($context->customerId);
        $sdkTransactionCreate->setMerchantReference($context->merchantReference);

        $sdkTransactionCreate->setSuccessUrl($context->successUrl);
        $sdkTransactionCreate->setFailedUrl($context->failedUrl);
        $sdkTransactionCreate->setAutoConfirmationEnabled($context->autoConfirmationEnabled);
        $sdkTransactionCreate->setChargeRetryEnabled($context->chargeRetryEnabled);

        if ($context->spaceViewId !== null) {
            $sdkTransactionCreate->setSpaceViewId($context->spaceViewId);
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
        } catch (\Exception $e) {
            $this->logger->error("Gateway: Failed to create transaction.", ['error' => $e->getMessage()]);
            throw $e;
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
        } catch (\Exception $e) {
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
            throw $e;
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
        $this->logger->debug("Gateway: Reading transaction $transactionId from Space $spaceId.");

        try {
            $sdkTransaction = $this->transactionService->read($spaceId, $transactionId);
            $result = $this->mapToTransaction($sdkTransaction);

            $this->logger->debug("Gateway: Transaction state is {$result->state->value}");

            return $result;
        } catch (\Exception $e) {
            $this->logger->error("Gateway: Failed to read transaction.", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Gets available payment methods for a transaction.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID.
     * @return PaymentMethod[] The available payment methods.
     */
    public function getAvailablePaymentMethods(int $spaceId, int $transactionId): array
    {
        $mode = $this->settings->getIntegrationMode()->value;
        $this->logger->debug("Gateway: Fetching payment methods for mode $mode.");

        $sdkResults = $this->transactionService->fetchPaymentMethods($spaceId, $transactionId, $mode);
        return array_map([$this, 'mapToPaymentMethod'], $sdkResults);
    }

    /**
     * Gets all active payment method configurations.
     *
     * @param int $spaceId The space ID.
     * @return PaymentMethod[] The payment method configurations.
     */
    public function getPaymentMethodConfigurations(int $spaceId): array
    {
        $sdkEntityQuery = new SdkEntityQuery();
        $sdkEntityQueryFilter = new SdkEntityQueryFilter();
        $sdkEntityQueryFilter->setType(SdkEntityQueryFilterType::LEAF);
        $sdkEntityQueryFilter->setOperator(SdkCriteriaOperator::EQUALS);
        $sdkEntityQueryFilter->setFieldName('state');
        $sdkEntityQueryFilter->setValue(SdkCreationEntityState::ACTIVE);
        $sdkEntityQuery->setFilter($sdkEntityQueryFilter);

        $results = $this->paymentMethodConfigService->search($spaceId, $sdkEntityQuery);
        $this->logger->debug(sprintf("Gateway: Fetched %d payment method configurations.", count($results)));

        return array_map(
            [$this, 'mapToPaymentMethod'],
            $results,
        );
    }

    /**
     * Gets the payment URL for a transaction.
     *
     * @param int $spaceId The space ID.
     * @param int $transactionId The transaction ID.
     * @return string The payment URL.
     */
    public function getPaymentUrl(int $spaceId, int $transactionId): string
    {
        $mode = $this->settings->getIntegrationMode();
        $this->logger->debug("Gateway: Fetching payment URL for mode {$mode->value}.");

        return match ($mode) {
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
    }

    /**
     * Maps a domain Address to an SDK AddressCreate.
     *
     * @param Address $source The source address.
     * @return SdkAddressCreate The SDK address.
     */
    private function mapAddress(Address $source): SdkAddressCreate
    {
        $sdkAddressCreate = new SdkAddressCreate();
        $sdkAddressCreate->setCity($source->city);
        $sdkAddressCreate->setCountry($source->country);
        $sdkAddressCreate->setFamilyName($source->familyName);
        $sdkAddressCreate->setGivenName($source->givenName);
        $sdkAddressCreate->setOrganizationName($source->organizationName);
        $sdkAddressCreate->setPhoneNumber($source->phoneNumber);
        $sdkAddressCreate->setPostcode($source->postcode);
        $sdkAddressCreate->setStreet($source->street);
        $sdkAddressCreate->setEmailAddress($source->emailAddress);
        $sdkAddressCreate->setSalutation($source->salutation);
        $sdkAddressCreate->setDateOfBirth($source->dateOfBirth ? \DateTime::createFromImmutable($source->dateOfBirth) : null);
        $sdkAddressCreate->setSalesTaxNumber($source->salesTaxNumber);
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
        $sdkLineItemCreate = new SdkLineItemCreate();
        $sdkLineItemCreate->setUniqueId($source->uniqueId);
        $sdkLineItemCreate->setSku($source->sku);
        $sdkLineItemCreate->setName($source->name);
        $sdkLineItemCreate->setQuantity($source->quantity);
        $sdkLineItemCreate->setAmountIncludingTax($source->amountIncludingTax);
        $sdkLineItemCreate->setShippingRequired($source->shippingRequired);

        if (!empty($source->attributes)) {
            $sdkLineItemCreate->setAttributes($source->attributes);
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
     * Maps an SDK PaymentMethodConfiguration to a domain object.
     *
     * @param SdkPaymentMethodConfiguration $sdkPaymentMethodConfiguration The SDK object.
     * @return PaymentMethod The domain object.
     */
    private function mapToPaymentMethod(SdkPaymentMethodConfiguration $sdkPaymentMethodConfiguration): PaymentMethod
    {
        return new PaymentMethod(
            id: (int) $sdkPaymentMethodConfiguration->getId(),
            spaceId: (int) $sdkPaymentMethodConfiguration->getLinkedSpaceId(),
            state: PaymentMethodState::from((string) $sdkPaymentMethodConfiguration->getState()),
            title: new LocalizedString($sdkPaymentMethodConfiguration->getResolvedTitle() ?? $sdkPaymentMethodConfiguration->getName()),
            description: new LocalizedString($sdkPaymentMethodConfiguration->getResolvedDescription() ?? $sdkPaymentMethodConfiguration->getDescription()),
            sortOrder: (int) $sdkPaymentMethodConfiguration->getSortOrder(),
            imageUrl: $sdkPaymentMethodConfiguration->getResolvedImageUrl(),
        );
    }

    /**
     * @inheritDoc
     */
    public function search(int $spaceId, TransactionSearchCriteria $criteria): array
    {
        $this->logger->debug("Gateway: Searching transactions in Space $spaceId.");

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
            return array_map([$this, 'mapToTransaction'], $results);
        } catch (\Exception $e) {
            $this->logger->error("Gateway: Failed to search transactions.", ['error' => $e->getMessage()]);
            throw $e;
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
        $sdkTransactionPending->setBillingAddress($this->mapAddress($context->billingAddress));
        $sdkTransactionPending->setShippingAddress($context->shippingAddress ? $this->mapAddress($context->shippingAddress) : null);
        $sdkTransactionPending->setLineItems(array_map([$this, 'mapLineItem'], $context->lineItems));
        $sdkTransactionPending->setCurrency($context->currencyCode);
        $sdkTransactionPending->setLanguage($context->language);
        $sdkTransactionPending->setCustomerEmailAddress($context->billingAddress->emailAddress);
        $sdkTransactionPending->setCustomerId($context->customerId);
        $sdkTransactionPending->setMerchantReference($context->merchantReference);
        $sdkTransactionPending->setSuccessUrl($context->successUrl);
        $sdkTransactionPending->setFailedUrl($context->failedUrl);

        try {
            $this->logger->debug("Gateway: Sending UPDATE request to SDK.");
            $sdkTransaction = $this->transactionService->update($context->spaceId, $sdkTransactionPending);
            $this->logger->debug("Gateway: Transaction updated successfully.", ['state' => (string) $sdkTransaction->getState()]);

            return $this->mapToTransaction($sdkTransaction);
        } catch (\Exception $e) {
            $this->logger->error("Gateway: Failed to update transaction.", ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
