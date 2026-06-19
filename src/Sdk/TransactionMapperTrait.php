<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk;

use WeArePlanet\PluginCore\Address\Address;
use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Token\State as TokenState;
use WeArePlanet\PluginCore\Token\Token;
use WeArePlanet\PluginCore\Transaction\State as StateEnum;
use WeArePlanet\PluginCore\Transaction\Transaction;
use WeArePlanet\Sdk\Model\Address as SdkAddress;
use WeArePlanet\Sdk\Model\LineItem as SdkLineItem;
use WeArePlanet\Sdk\Model\LineItemType as SdkLineItemType;
use WeArePlanet\Sdk\Model\Token as SdkToken;
use WeArePlanet\Sdk\Model\Transaction as SdkTransaction;

/**
 * Shared SDK Transaction -> domain mapping.
 *
 * Centralizes the conversion of an SDK Transaction (and its embedded token,
 * addresses and line items) into the domain {@see Transaction}, so the standard
 * checkout gateway and the recurring gateway map identically and preserve all
 * failure data ($failureReason, $userFailureMessage) and timestamps.
 */
trait TransactionMapperTrait
{
    use DateTimeMapperTrait;
    use FailureReasonMapperTrait;
    use TokenMapperTrait;

    /**
     * Maps an SDK Transaction to a domain Transaction.
     *
     * @param SdkTransaction $sdkTransaction The SDK transaction.
     * @return Transaction The domain transaction.
     */
    protected function mapToTransaction(SdkTransaction $sdkTransaction): Transaction
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
            $domain->failureReason = $this->mapSdkFailureReason($reason);
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

    /**
     * Maps an SDK Address to a domain Address.
     *
     * @param SdkAddress $sdkAddress
     * @return Address
     */
    protected function mapToAddress(SdkAddress $sdkAddress): Address
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

    /**
     * Maps an SDK LineItem to a domain LineItem.
     *
     * @param SdkLineItem $sdkItem
     * @return LineItem
     */
    protected function mapToLineItem(SdkLineItem $sdkItem): LineItem
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

}
