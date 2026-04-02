<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook\Enum;

/**
 * Defines the Webhook Listeners, linking a unique ID to a technical name.
 * This acts as the single source of truth for all listener types, and it's retrieved from the weareplanet backend.
 */
enum WebhookListener: int
{
    case CHARGE = 1472041836176;
    case CHARGE_ATTEMPT = 1472041801860;
    case CHARGE_FLOW = 1472041871385;
    case CHARGE_FLOW_LEVEL = 1472041819798;
    case CHARGE_FLOW_LEVEL_CONFIGURATION = 1472041847188;
    case CHARGE_FLOW_LEVEL_PAYMENT_LINK = 1582966575060;
    case CONDITION = 1472041875547;
    case DELIVERY_INDICATION = 1472041819799;
    case MANUAL_TASK = 1487165678181;
    case PAYMENT_CONNECTOR_CONFIGURATION = 1472041843695;
    case PAYMENT_METHOD_CONFIGURATION = 1472041857405;
    case PAYMENT_PROCESSOR_CONFIGURATION = 1472041863438;
    case PAYMENT_TERMINAL = 1582966575099;
    case REFUND = 1472041839405;
    case TOKEN = 1472041806455;
    case TOKEN_VERSION = 1472041811051;
    case TRANSACTION = 1472041829003;
    case TRANSACTION_COMPLETION = 1472041831364;
    case TRANSACTION_GROUP = 1472041814414;
    case TRANSACTION_INVOICE = 1472041816898;
    case TRANSACTION_VOID = 1472041867364;

    public function getTechnicalName(): string
    {
        return match ($this) {
            self::CHARGE => 'Charge',
            self::CHARGE_ATTEMPT => 'ChargeAttempt',
            self::CHARGE_FLOW => 'ChargeFlow',
            self::CHARGE_FLOW_LEVEL => 'ChargeFlowLevel',
            self::CHARGE_FLOW_LEVEL_CONFIGURATION => 'ChargeFlowLevelConfiguration',
            self::CHARGE_FLOW_LEVEL_PAYMENT_LINK => 'ChargeFlowLevelPaymentLink',
            self::CONDITION => 'Condition',
            self::DELIVERY_INDICATION => 'DeliveryIndication',
            self::MANUAL_TASK => 'ManualTask',
            self::PAYMENT_CONNECTOR_CONFIGURATION => 'PaymentConnectorConfiguration',
            self::PAYMENT_METHOD_CONFIGURATION => 'PaymentMethodConfiguration',
            self::PAYMENT_PROCESSOR_CONFIGURATION => 'PaymentProcessorConfiguration',
            self::PAYMENT_TERMINAL => 'PaymentTerminal',
            self::REFUND => 'Refund',
            self::TOKEN => 'Token',
            self::TOKEN_VERSION => 'TokenVersion',
            self::TRANSACTION => 'Transaction',
            self::TRANSACTION_COMPLETION => 'TransactionCompletion',
            self::TRANSACTION_GROUP => 'TransactionGroup',
            self::TRANSACTION_INVOICE => 'TransactionInvoice',
            self::TRANSACTION_VOID => 'TransactionVoid',
        };
    }

    public static function fromTechnicalName(string $technicalName): self
    {
        foreach (self::cases() as $case) {
            if ($case->getTechnicalName() === $technicalName) {
                return $case;
            }
        }
        throw new \ValueError('"' . $technicalName . '" is not a valid technical name for enum ' . self::class);
    }

    public function getStateEnumClass(): ?string
    {
        return match ($this) {
            self::DELIVERY_INDICATION => \WeArePlanet\PluginCore\DeliveryIndication\State::class,
            self::MANUAL_TASK => \WeArePlanet\PluginCore\ManualTask\State::class,
            self::REFUND => \WeArePlanet\PluginCore\Refund\State::class,
            self::TOKEN => \WeArePlanet\PluginCore\Token\State::class,
            self::TOKEN_VERSION => \WeArePlanet\PluginCore\Token\Version\State::class,
            self::TRANSACTION => \WeArePlanet\PluginCore\Transaction\State::class,
            self::TRANSACTION_COMPLETION => \WeArePlanet\PluginCore\Transaction\Completion\State::class,
            self::TRANSACTION_INVOICE => \WeArePlanet\PluginCore\Transaction\Invoice\State::class,
            self::TRANSACTION_VOID => \WeArePlanet\PluginCore\Transaction\Void\State::class,
            default => null,
        };
    }
}
