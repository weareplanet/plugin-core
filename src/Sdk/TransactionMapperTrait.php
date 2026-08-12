<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Sdk;

use WeArePlanet\PluginCore\Address\Address;
use WeArePlanet\PluginCore\Customer\CompanyDetails;
use WeArePlanet\PluginCore\Customer\Gender;
use WeArePlanet\PluginCore\Customer\PersonalDetails;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Token\Token;
use WeArePlanet\PluginCore\Transaction\State as StateEnum;
use WeArePlanet\PluginCore\Transaction\Transaction;
use WeArePlanet\PluginCore\Transaction\TransactionEnvironment;
use WeArePlanet\PluginCore\Transaction\TransactionPaymentMethod;
use WeArePlanet\Sdk\Model\Address as SdkAddress;
use WeArePlanet\Sdk\Model\PaymentConnectorConfiguration as SdkPaymentConnectorConfiguration;
use WeArePlanet\Sdk\Model\PaymentMethodConfiguration as SdkPaymentMethodConfiguration;
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
    use LineItemMapperTrait;
    use TokenMapperTrait;

    /**
     * Maps an SDK Address to a domain Address (geographic data only; identity
     * data is mapped separately via {@see mapToPersonalDetails} and
     * {@see mapToCompanyDetails}).
     *
     * @param SdkAddress $sdkAddress
     * @return Address
     */
    protected function mapToAddress(SdkAddress $sdkAddress): Address
    {
        $address = new Address();
        $address->city = $sdkAddress->getCity();
        $address->country = $sdkAddress->getCountry();
        $address->dependentLocality = $sdkAddress->getDependentLocality();
        $address->phoneNumber = $sdkAddress->getPhoneNumber();
        $address->postalState = $sdkAddress->getPostalState();
        $address->postcode = $sdkAddress->getPostcode();
        $address->sortingCode = $sdkAddress->getSortingCode();
        $address->street = $sdkAddress->getStreet();
        return $address;
    }

    /**
     * Maps the corporate identity fields of an SDK Address to the customer's CompanyDetails.
     *
     * @param SdkAddress $sdkAddress
     * @return CompanyDetails
     */
    protected function mapToCompanyDetails(SdkAddress $sdkAddress): CompanyDetails
    {
        return new CompanyDetails(
            commercialRegisterNumber: $sdkAddress->getCommercialRegisterNumber(),
            organizationName: $sdkAddress->getOrganizationName(),
            salesTaxNumber: $sdkAddress->getSalesTaxNumber(),
        );
    }

    /**
     * Maps the identity fields of an SDK Address to the customer's PersonalDetails.
     *
     * @param SdkAddress $sdkAddress
     * @return PersonalDetails
     */
    protected function mapToPersonalDetails(SdkAddress $sdkAddress): PersonalDetails
    {
        return new PersonalDetails(
            dateOfBirth: $this->toDateTimeImmutable($sdkAddress->getDateOfBirth()),
            emailAddress: $sdkAddress->getEmailAddress(),
            familyName: $sdkAddress->getFamilyName(),
            gender: Gender::tryFrom((string) $sdkAddress->getGender()),
            givenName: $sdkAddress->getGivenName(),
            mobilePhoneNumber: $sdkAddress->getMobilePhoneNumber(),
            salutation: $sdkAddress->getSalutation(),
            socialSecurityNumber: $sdkAddress->getSocialSecurityNumber(),
        );
    }

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
            $domain->personalDetails = $this->mapToPersonalDetails($sdkTransaction->getBillingAddress());
            $domain->companyDetails = $this->mapToCompanyDetails($sdkTransaction->getBillingAddress());
        }

        if ($sdkTransaction->getShippingAddress()) {
            $domain->shippingAddress = $this->mapToAddress($sdkTransaction->getShippingAddress());
        }

        // Snapshot of the context this transaction ran in, captured as-is so a stored
        // transaction keeps reporting what was used rather than what is configured now.
        $domain->environment = new TransactionEnvironment(
            spaceViewId: $sdkTransaction->getSpaceViewId(),
            language: $sdkTransaction->getLanguage(),
        );

        $connectorConfiguration = $sdkTransaction->getPaymentConnectorConfiguration();
        if ($connectorConfiguration !== null) {
            $domain->paymentMethod = $this->mapToTransactionPaymentMethod($connectorConfiguration);
        }

        return $domain;
    }


    /**
     * Maps an SDK payment connector configuration to the immutable payment method
     * snapshot held by a transaction.
     *
     * @param SdkPaymentConnectorConfiguration $connectorConfiguration The SDK payment
     *        connector configuration embedded in the transaction.
     * @return TransactionPaymentMethod The snapshot of the values in effect for the
     *         transaction; individual properties are null where the API omitted them.
     */
    protected function mapToTransactionPaymentMethod(
        SdkPaymentConnectorConfiguration $connectorConfiguration,
    ): TransactionPaymentMethod {
        $paymentMethodId = null;
        $resolvedImageUrl = null;

        // The SDK declares the embedded payment method configuration as always present,
        // but the API omits it while no payment method has been resolved yet.
        $configuration = $connectorConfiguration->getPaymentMethodConfiguration();
        if ($configuration instanceof SdkPaymentMethodConfiguration) {
            $paymentMethodId = $configuration->getId();
            $resolvedImageUrl = $configuration->getResolvedImageUrl();
        }

        return new TransactionPaymentMethod(
            paymentMethodId: $paymentMethodId,
            connectorId: $connectorConfiguration->getConnector(),
            resolvedImageUrl: $resolvedImageUrl,
        );
    }

}
