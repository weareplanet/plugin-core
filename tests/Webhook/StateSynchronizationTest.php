<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Tests\Webhook;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeArePlanet\PluginCore\DeliveryIndication\State as PluginCoreDeliveryIndicationState;
use WeArePlanet\PluginCore\ManualTask\State as PluginCoreManualTaskState;
use WeArePlanet\PluginCore\Refund\State as PluginCoreRefundState;
use WeArePlanet\PluginCore\Token\Version\State as PluginCoreTokenVersionState;
use WeArePlanet\PluginCore\Transaction\Completion\State as PluginCoreTransactionCompletionState;
use WeArePlanet\PluginCore\Transaction\Invoice\State as PluginCoreTransactionInvoiceState;
use WeArePlanet\PluginCore\Transaction\State as PluginCoreTransactionState;
use WeArePlanet\PluginCore\Transaction\Void\State as PluginCoreTransactionVoidState;
use WeArePlanet\Sdk\Model\DeliveryIndicationState as SdkDeliveryIndicationState;
use WeArePlanet\Sdk\Model\ManualTaskState as SdkManualTaskState;
use WeArePlanet\Sdk\Model\RefundState as SdkRefundState;
use WeArePlanet\Sdk\Model\TokenVersionState as SdkTokenVersionState;
use WeArePlanet\Sdk\Model\TransactionCompletionState as SdkTransactionCompletionState;
use WeArePlanet\Sdk\Model\TransactionInvoiceState as SdkTransactionInvoiceState;
use WeArePlanet\Sdk\Model\TransactionState as SdkTransactionState;
use WeArePlanet\Sdk\Model\TransactionVoidState as SdkTransactionVoidState;

class StateSynchronizationTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: class-string}>
     */
    public static function stateMappingProvider(): array
    {
        return [
            'Delivery Indication States' => [
                SdkDeliveryIndicationState::class,
                PluginCoreDeliveryIndicationState::class,
            ],
            'Refund States' => [
                SdkRefundState::class,
                PluginCoreRefundState::class,
            ],
            'Manual Task States' => [
                SdkManualTaskState::class,
                PluginCoreManualTaskState::class,
            ],
            'Token Version States' => [
                SdkTokenVersionState::class,
                PluginCoreTokenVersionState::class,
            ],
            'Transaction States' => [
                SdkTransactionState::class,
                PluginCoreTransactionState::class,
            ],
            'Transaction Completion States' => [
                SdkTransactionCompletionState::class,
                PluginCoreTransactionCompletionState::class,
            ],
            'Transaction Invoice States' => [
                SdkTransactionInvoiceState::class,
                PluginCoreTransactionInvoiceState::class,
            ],
            'Transaction Void States' => [
                SdkTransactionVoidState::class,
                PluginCoreTransactionVoidState::class,
            ],
        ];
    }

    #[DataProvider('stateMappingProvider')]
    public function testInternalEnumCoversAllSdkStates(string $sdkStateClass, string $internalEnumClass): void
    {
        $sdkStates = $sdkStateClass::getAllowableEnumValues();
        $internalEnumValues = array_map(fn ($case) => $case->value, $internalEnumClass::cases());

        foreach ($sdkStates as $sdkState) {
            $this->assertContains(
                $sdkState,
                $internalEnumValues,
                "SDK state '{$sdkState}' is missing from internal enum {$internalEnumClass}",
            );
        }
    }
}
