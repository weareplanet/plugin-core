<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk\WebServiceAPIV2;

use WeArePlanet\PluginCore\Address\Address;
use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\PaymentMethod\PaymentMethod;
use WeArePlanet\PluginCore\PaymentMethod\State as PaymentMethodState;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Settings\IntegrationMode as IntegrationModeEnum;
use WeArePlanet\PluginCore\Settings\Settings;
use WeArePlanet\PluginCore\Tax\Tax;
use WeArePlanet\PluginCore\Token\State as TokenState;
use WeArePlanet\PluginCore\Token\Token;
use WeArePlanet\PluginCore\Transaction\Exception\TransactionException;
use WeArePlanet\PluginCore\Transaction\State as StateEnum;
use WeArePlanet\PluginCore\Transaction\Transaction;
use WeArePlanet\PluginCore\Transaction\TransactionContext;
use WeArePlanet\PluginCore\Transaction\TransactionGatewayInterface;
use WeArePlanet\PluginCore\Transaction\TransactionSearchCriteria;
use WeArePlanet\Sdk\Model\Address as SdkAddress;
use WeArePlanet\Sdk\Model\AddressCreate as SdkAddressCreate;
use WeArePlanet\Sdk\Model\CreationEntityState as SdkCreationEntityState;
use WeArePlanet\Sdk\Model\LineItem as SdkLineItem;
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

class TransactionGateway implements TransactionGatewayInterface
{
    private SdkPaymentMethodConfigurationsService $paymentMethodConfigService;
    private SdkTransactionsService $transactionsService;

    public function __construct(
        private readonly SdkProvider $sdkProvider,
        private readonly LoggerInterface $logger,
        private readonly Settings $settings,
    ) {
        $this->transactionsService = $this->sdkProvider->getService(SdkTransactionsService::class);
        $this->paymentMethodConfigService = $this->sdkProvider->getService(SdkPaymentMethodConfigurationsService::class);
    }

    public function create(TransactionContext $context): Transaction
    {
        $this->logger->debug("Gateway: Preparing to CREATE transaction (V2).", [
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
            $this->logger->error("Gateway: Failed to create transaction: {$e->getMessage()}");
            throw new TransactionException("Unable to create transaction: {$e->getMessage()}", 0, $e);
        }
    }

    public function find(int $spaceId, int $transactionId): ?Transaction
    {
        try {
            $sdkTransaction = $this->transactionsService->getPaymentTransactionsId($transactionId, $spaceId, ['billingAddress', 'shippingAddress', 'lineItems', 'token']);
            return $this->mapToTransaction($sdkTransaction);
        } catch (\Throwable $e) {
            $this->logger->debug("Gateway: Transaction $transactionId not found in Space $spaceId.");
            return null;
        }
    }

    public function get(int $spaceId, int $transactionId): Transaction
    {
        $this->logger->debug("Gateway: Reading transaction $transactionId from Space $spaceId.");

        try {
            $sdkTransaction = $this->transactionsService->getPaymentTransactionsId($transactionId, $spaceId, ['billingAddress', 'shippingAddress', 'lineItems', 'token']);
            $result = $this->mapToTransaction($sdkTransaction);

            $this->logger->debug("Gateway: Transaction state is " . $result->state->value);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error("Gateway: Failed to read transaction: {$e->getMessage()}");
            throw new TransactionException("Unable to read transaction: {$e->getMessage()}", 0, $e);
        }
    }

    public function getAvailablePaymentMethods(int $spaceId, int $transactionId): array
    {
        $mode = $this->settings->getIntegrationMode()->value;
        $this->logger->debug("Gateway: Fetching payment methods for mode $mode.");

        // V2: getPaymentTransactionsIdPaymentMethodConfigurations
        $sdkResults = $this->transactionsService->getPaymentTransactionsIdPaymentMethodConfigurations($transactionId, $mode, $spaceId);
        $items = (is_object($sdkResults) && method_exists($sdkResults, 'getData')) ? $sdkResults->getData() : (array)$sdkResults;
        return array_map([$this, 'mapToPaymentMethod'], $items);
    }

    public function getPaymentMethodConfigurations(int $spaceId): array
    {
        // Search for active payment method configurations using the V2 query syntax.
        $query = "state:ACTIVE";

        try {
            $results = $this->paymentMethodConfigService->getPaymentMethodConfigurationsSearch($spaceId, null, null, null, null, $query);
            $items = (is_object($results) && method_exists($results, 'getData')) ? $results->getData() : (array)$results;
            $this->logger->debug(sprintf("Gateway: Fetched %d payment method configurations.", count($items)));

            return array_map([$this, 'mapToPaymentMethod'], $items);
        } catch (\Throwable $e) {
            $this->logger->error("Gateway: Failed to fetch PMC: {$e->getMessage()}");
            return [];
        }
    }

    public function getPaymentUrl(int $spaceId, int $transactionId): string
    {
        $mode = $this->settings->getIntegrationMode();
        $this->logger->debug("Gateway: Fetching payment URL for mode {$mode->value}.");

        return match ($mode) {
            IntegrationModeEnum::PAYMENT_PAGE => $this->transactionsService
                ->getPaymentTransactionsIdPaymentPageUrl($transactionId, $spaceId),

            IntegrationModeEnum::IFRAME => $this->transactionsService
                ->getPaymentTransactionsIdIframeJavascriptUrl($transactionId, $spaceId),

            IntegrationModeEnum::LIGHTBOX => $this->transactionsService
                ->getPaymentTransactionsIdLightboxJavascriptUrl($transactionId, $spaceId),
        };
    }

    private function mapAddress(Address $source): SdkAddressCreate
    {
        $sdkAddressCreate = new SdkAddressCreate();
        $sdkAddressCreate->setCity($source->city);
        $sdkAddressCreate->setCountry($source->country);

        if ($source->familyName !== null) {
            $sdkAddressCreate->setFamilyName($source->familyName);
        }
        if ($source->givenName !== null) {
            $sdkAddressCreate->setGivenName($source->givenName);
        }
        if ($source->organizationName !== null) {
            $sdkAddressCreate->setOrganizationName($source->organizationName);
        }
        if ($source->phoneNumber !== null) {
            $sdkAddressCreate->setPhoneNumber($source->phoneNumber);
        }
        if ($source->postcode !== null) {
            $sdkAddressCreate->setPostcode($source->postcode);
        }
        if ($source->street !== null) {
            $sdkAddressCreate->setStreet($source->street);
        }
        if ($source->emailAddress !== null) {
            $sdkAddressCreate->setEmailAddress($source->emailAddress);
        }
        if ($source->salutation !== null) {
            $sdkAddressCreate->setSalutation($source->salutation);
        }
        if ($source->dateOfBirth !== null) {
            $sdkAddressCreate->setDateOfBirth(\DateTime::createFromImmutable($source->dateOfBirth));
        }
        if ($source->salesTaxNumber !== null) {
            $sdkAddressCreate->setSalesTaxNumber($source->salesTaxNumber);
        }

        return $sdkAddressCreate;
    }

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

    private function mapTax(Tax $source): SdkTaxCreate
    {
        $sdkTaxCreate = new SdkTaxCreate();
        $sdkTaxCreate->setTitle($source->title);
        $sdkTaxCreate->setRate($source->rate);
        return $sdkTaxCreate;
    }

    private function mapToAddress(SdkAddress $sdkAddress): Address
    {
        $address = new Address();
        $address->city = $sdkAddress->getCity();
        $address->country = $sdkAddress->getCountry();
        $address->familyName = $sdkAddress->getFamilyName();
        $address->givenName = $sdkAddress->getGivenName();
        $address->organizationName = $sdkAddress->getOrganizationName();
        $address->phoneNumber = $sdkAddress->getPhoneNumber();
        $address->postcode = $sdkAddress->getPostcode();
        $address->street = $sdkAddress->getStreet();
        $address->emailAddress = $sdkAddress->getEmailAddress();
        $address->salutation = $sdkAddress->getSalutation();
        $address->dateOfBirth = $this->toDateTimeImmutable($sdkAddress->getDateOfBirth());
        $address->salesTaxNumber = $sdkAddress->getSalesTaxNumber();
        return $address;
    }

    private function mapToLineItem(SdkLineItem $sdkItem): LineItem
    {
        $item = new LineItem();
        $item->uniqueId = $sdkItem->getUniqueId();
        $item->sku = $sdkItem->getSku();
        $item->name = $sdkItem->getName();
        $item->quantity = $sdkItem->getQuantity();
        $item->amountIncludingTax = $sdkItem->getAmountIncludingTax();
        $item->type = match ($sdkItem->getType()) {
            SdkLineItemType::DISCOUNT => LineItem::TYPE_DISCOUNT,
            SdkLineItemType::SHIPPING => LineItem::TYPE_SHIPPING,
            SdkLineItemType::FEE => LineItem::TYPE_FEE,
            default => LineItem::TYPE_PRODUCT,
        };

        return $item;
    }

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

    private function mapToToken(SdkToken $sdkToken): Token
    {
        $token = new Token();
        $token->id = $sdkToken->getId();
        $token->spaceId = $sdkToken->getLinkedSpaceId();
        $token->version = $sdkToken->getVersion();

        $token->state = match ((string) $sdkToken->getState()) {
            'ACTIVE' => TokenState::ACTIVE,
            'CREATE' => TokenState::CREATE,
            'DELETED' => TokenState::DELETED,
            'DELETING' => TokenState::DELETING,
            'INACTIVE' => TokenState::INACTIVE,
            default => TokenState::ACTIVE,
        };

        return $token;
    }

    private function mapToTransaction(SdkTransaction $sdkTransaction): Transaction
    {
        $domain = new Transaction();
        $domain->id = $sdkTransaction->getId();
        $domain->spaceId = $sdkTransaction->getLinkedSpaceId();
        $domain->version = $sdkTransaction->getVersion();

        $domain->state = match ((string) $sdkTransaction->getState()) {
            'PENDING' => StateEnum::PENDING,
            'CONFIRMED' => StateEnum::CONFIRMED,
            'PROCESSING' => StateEnum::PROCESSING,
            'FAILED' => StateEnum::FAILED,
            'AUTHORIZED' => StateEnum::AUTHORIZED,
            'VOIDED' => StateEnum::VOIDED,
            'COMPLETED' => StateEnum::COMPLETED,
            'FULFILL' => StateEnum::FULFILL,
            'DECLINE' => StateEnum::DECLINE,
            default => StateEnum::PENDING,
        };

        $domain->merchantReference = $sdkTransaction->getMerchantReference();
        $domain->customerId = $sdkTransaction->getCustomerId();
        $domain->currency = $sdkTransaction->getCurrency();

        $domain->authorizedAmount = $sdkTransaction->getAuthorizationAmount();
        $domain->refundedAmount = $sdkTransaction->getRefundedAmount();

        if ($sdkTransaction->getLineItems()) {
            $domain->lineItems = array_map([$this, 'mapToLineItem'], $sdkTransaction->getLineItems());
        }

        $domain->createdOn = $this->toDateTimeImmutable($sdkTransaction->getCreatedOn());
        $domain->authorizedOn = $this->toDateTimeImmutable($sdkTransaction->getAuthorizedOn());
        $domain->completedOn = $this->toDateTimeImmutable($sdkTransaction->getCompletedOn());
        $domain->failedOn = $this->toDateTimeImmutable($sdkTransaction->getFailedOn());
        $domain->processingOn = $this->toDateTimeImmutable($sdkTransaction->getProcessingOn());

        $domain->userFailureMessage = new LocalizedString($sdkTransaction->getUserFailureMessage());

        $reason = $sdkTransaction->getFailureReason();
        if ($reason !== null) {
            $domain->failureReason = new LocalizedString($reason->getDescription() ?? $reason->getName());
        }

        if ($sdkTransaction->getToken()) {
            $domain->token = $this->mapToToken($sdkTransaction->getToken());
        }

        if ($sdkTransaction->getBillingAddress()) {
            $domain->billingAddress = $this->mapToAddress($sdkTransaction->getBillingAddress());
        }

        if ($sdkTransaction->getShippingAddress()) {
            $domain->shippingAddress = $this->mapToAddress($sdkTransaction->getShippingAddress());
        }

        return $domain;
    }

    public function search(int $spaceId, TransactionSearchCriteria $criteria): array
    {
        $this->logger->debug("Gateway: Searching transactions in Space $spaceId.");

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
            return array_map([$this, 'mapToTransaction'], $items);
        } catch (\Throwable $e) {
            $this->logger->error("Gateway: Failed to search transactions: {$e->getMessage()}");
            throw new TransactionException("Unable to search transactions: {$e->getMessage()}", 0, $e);
        }
    }

    private function toDateTimeImmutable(?\DateTime $date): ?\DateTimeImmutable
    {
        if (!$date) {
            return null;
        }
        return \DateTimeImmutable::createFromMutable($date);
    }

    public function update(int $transactionId, int $version, TransactionContext $context): Transaction
    {
        $this->logger->debug("Gateway: Preparing to UPDATE transaction (V2).", ['id' => $transactionId]);

        $sdkTransactionPending = new SdkTransactionPending();
        $sdkTransactionPending->setVersion($version);

        // Map the NEW data from the Context
        if ($context->billingAddress) {
            $sdkTransactionPending->setBillingAddress($this->mapAddress($context->billingAddress));
        }
        if ($context->shippingAddress) {
            $sdkTransactionPending->setShippingAddress($this->mapAddress($context->shippingAddress));
        }
        $sdkTransactionPending->setLineItems(array_map([$this, 'mapLineItem'], $context->lineItems));
        $sdkTransactionPending->setCurrency($context->currencyCode);
        $sdkTransactionPending->setLanguage($context->language);
        if ($context->billingAddress->emailAddress) {
            $sdkTransactionPending->setCustomerEmailAddress($context->billingAddress->emailAddress);
        }
        $sdkTransactionPending->setCustomerId($context->customerId);
        $sdkTransactionPending->setMerchantReference($context->merchantReference);
        $sdkTransactionPending->setSuccessUrl($context->successUrl);
        $sdkTransactionPending->setFailedUrl($context->failedUrl);

        try {
            $this->logger->debug("Gateway: Sending UPDATE request to SDK (patchPaymentTransactionsId).");
            // V2: patchPaymentTransactionsId
            // Arguments: $id, $space, $transaction_pending
            $sdkTransaction = $this->transactionsService->patchPaymentTransactionsId($transactionId, $context->spaceId, $sdkTransactionPending);
            $this->logger->debug("Gateway: Transaction updated successfully.", ['state' => (string) $sdkTransaction->getState()]);

            return $this->mapToTransaction($sdkTransaction);
        } catch (\Throwable $e) {
            $this->logger->error("Gateway: Failed to update transaction: {$e->getMessage()}");
            throw new TransactionException("Unable to update transaction: {$e->getMessage()}", 0, $e);
        }
    }
}
