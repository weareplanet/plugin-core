<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Refund;

use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\LineItem\LineItemCollection;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Refund\Exception\InvalidRefundException;
use WeArePlanet\PluginCore\Transaction\Transaction;
use WeArePlanet\PluginCore\Transaction\TransactionService;

class RefundService
{
    public function __construct(
        private readonly RefundGatewayInterface $gateway,
        private readonly TransactionService $transactionService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Creates a refund for a transaction.
     *
     * @param int $spaceId
     * @param RefundContext $context
     * @return Refund
     * @throws InvalidRefundException
     * @throws \Throwable
     */
    public function createRefund(int $spaceId, RefundContext $context): Refund
    {
        $this->logger->debug("Starting refund process.", [
            'transactionId' => $context->transactionId,
            'spaceId' => $spaceId,
        ]);

        // Load the original transaction to verify refund possibility.
        $originalTransaction = $this->transactionService->getTransaction($spaceId, $context->transactionId);

        // Validate the refund context against the original transaction data.
        $this->validateRefund($originalTransaction, $context);

        // Execute the refund operation via the gateway.
        return $this->gateway->refund($spaceId, $context);
    }

    /**
     * Finds a line item by its unique ID.
     *
     * @param LineItem[] $lineItems
     * @param string $uniqueId
     * @return LineItem|null
     */
    private function findLineItem(array $lineItems, string $uniqueId): ?LineItem
    {
        foreach ($lineItems as $item) {
            if ($item->uniqueId === $uniqueId) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Returns a list of line items that can be refunded.
     * Filters out discounts and items with zero or negative amounts.
     *
     * @param Transaction $transaction
     * @return LineItemCollection
     */
    public function getRefundableLineItems(Transaction $transaction): LineItemCollection
    {
        $refundableItems = [];

        foreach ($transaction->lineItems as $item) {
            // Check if item is not a discount AND has a positive amount
            if ($item->type !== LineItem::TYPE_DISCOUNT && $item->amountIncludingTax > 0.0) {
                $refundableItems[] = $item;
            }
        }

        return new LineItemCollection(...$refundableItems);
    }

    public function getRefunds(int $spaceId, int $transactionId): RefundCollection
    {
        return $this->gateway->findByTransaction($spaceId, $transactionId);
    }

    /**
     * Validates the refund request against the original transaction.
     *
     * @param Transaction $originalTransaction
     * @param RefundContext $context
     * @throws InvalidRefundException
     */
    private function validateRefund(Transaction $originalTransaction, RefundContext $context): void
    {
        // Check Global Amount
        $authorizedAmount = $originalTransaction->authorizedAmount ?? 0.0;
        $refundedAmount = $originalTransaction->refundedAmount ?? 0.0;
        $remainingAmount = $authorizedAmount - $refundedAmount;

        // Compare the request amount against the remaining balance.
        // We use a strict comparison here as refund amounts are typically handled as exact values.

        if ($context->amount > $remainingAmount) {
            $this->logger->error("Validation failed: refund amount exceeds the remaining authorized amount.", [
                'transactionId' => $context->transactionId,
                'requestedAmount' => $context->amount,
                'remainingAmount' => $remainingAmount,
            ]);
            throw new InvalidRefundException(
                "Refund amount {$context->amount} exceeds the remaining authorized amount {$remainingAmount} for transaction {$context->transactionId}.",
                new LocalizedString("Refund amount exceeds the remaining authorized amount."),
            );
        }

        // Check Line Items and Consistency
        if (!empty($context->lineItems)) {
            $calculatedTotalReduction = 0.0;

            foreach ($context->lineItems as $refundItem) {
                // standardized access
                $uId = $refundItem['uniqueId'];
                $quantity = (float)$refundItem['quantity'];
                $unitPriceReduction = (float)$refundItem['amount'];

                $originalItem = $this->findLineItem($originalTransaction->lineItems, $uId);

                if (!$originalItem) {
                    throw new InvalidRefundException(
                        "Line item with Unique ID '$uId' not found in original transaction {$originalTransaction->id}.",
                        new LocalizedString("Line item with Unique ID '$uId' not found in original transaction."),
                    );
                }

                // Validate specific item types and amounts.
                if ($originalItem->type === LineItem::TYPE_DISCOUNT) {
                    throw new InvalidRefundException(
                        "Cannot refund line item '{$uId}'. Discounts cannot be refunded.",
                        new LocalizedString("Cannot refund line item '{$uId}'. Discounts cannot be refunded."),
                    );
                }

                if ($originalItem->amountIncludingTax <= 0.0) {
                    throw new InvalidRefundException(
                        "Cannot refund line item '{$uId}'. Items with zero or negative amounts cannot be refunded.",
                        new LocalizedString("Cannot refund line item '{$uId}'. Items with zero or negative amounts cannot be refunded."),
                    );
                }

                // Calculate implied reduction for this item
                // Each item's reduction is calculated based on returned quantity and any price adjustments.
                // We derive the unit price from the total amount as LineItems store the total.
                $originalUnitPrice = 0.0;
                if ($originalItem->quantity > 0) {
                    $originalUnitPrice = $originalItem->amountIncludingTax / $originalItem->quantity;
                }

                // If refund quantity exceeds original, that's an error.
                if ($quantity > $originalItem->quantity) {
                    throw new InvalidRefundException(
                        "Refund quantity $quantity for item '$uId' exceeds original quantity {$originalItem->quantity} in transaction {$originalTransaction->id}.",
                        new LocalizedString("Refund quantity exceeds original quantity."),
                    );
                }

                $remainingQuantity = $originalItem->quantity - $quantity;

                $itemTotalReduction = ($quantity * $originalUnitPrice) + ($remainingQuantity * $unitPriceReduction);

                if ($itemTotalReduction > $originalItem->amountIncludingTax + 0.01) {
                    throw new InvalidRefundException(
                        sprintf(
                            "Refund amount %.2f for item '%s' exceeds original item amount %.2f in transaction %d.",
                            $itemTotalReduction,
                            $uId,
                            $originalItem->amountIncludingTax,
                            $originalTransaction->id,
                        ),
                        new LocalizedString("Refund amount exceeds original item amount."),
                    );
                }

                $calculatedTotalReduction += $itemTotalReduction;
            }

            // Consistency Check: Expected Total vs Context Total
            // Allow small float epsilon difference
            if (abs($calculatedTotalReduction - $context->amount) > 0.01) {
                throw new InvalidRefundException(
                    sprintf(
                        "Consistency Error: Total provided refund amount (%.2f) does not match the sum of line item reductions (%.2f) for transaction %d.",
                        $context->amount,
                        $calculatedTotalReduction,
                        $originalTransaction->id,
                    ),
                    new LocalizedString("Total refund amount does not match the sum of line item reductions."),
                );
            }
        }
    }
}
