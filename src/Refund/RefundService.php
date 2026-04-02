<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Refund;

use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Refund\Exception\InvalidRefundException;
use WeArePlanet\PluginCore\Transaction\Transaction;
use WeArePlanet\PluginCore\Transaction\TransactionService;
use WeArePlanet\PluginCore\LineItem\LineItem;

/**
 * Manages the processing and validation of refund requests.
 *
 * This service ensuring that refunds follow business rules: they must not exceed
 * the original authorized amount, they must be consistent with the line item
 * reductions specified, and they cannot be applied to non-refundable items like discounts.
 */
class RefundService
{
    /**
     * @param RefundGatewayInterface $gateway Interface to the persistence or API layer for refunds.
     * @param TransactionService $transactionService Used to fetch the parent transaction for validation.
     * @param LoggerInterface $logger The system logger.
     */
    public function __construct(
        private readonly RefundGatewayInterface $gateway,
        private readonly TransactionService $transactionService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Orchestrates the creation of a new refund.
     *
     * This method validates the refund against the original transaction's state
     * before committing the request to the gateway.
     *
     * @param int $spaceId The identity space.
     * @param RefundContext $context The refund details (amount, line items, external ref).
     * @return Refund The persisted refund object.
     * @throws InvalidRefundException If business invariants are violated.
     * @throws \Throwable For system-level failures.
     */
    public function createRefund(int $spaceId, RefundContext $context): Refund
    {
        $this->logger->debug("Starting refund process for Transaction {$context->transactionId} in Space $spaceId.");

        // Transaction State Retrieval
        $originalTransaction = $this->transactionService->getTransaction($spaceId, $context->transactionId);

        // Business Rule Validation
        $this->validateRefund($originalTransaction, $context);

        // Gateway Execution
        return $this->gateway->refund($spaceId, $context);
    }

    /**
     * Retrieves a list of line items from a transaction that are eligible for refunding.
     *
     * Discounts and items with zero/negative amounts are excluded because they
     * represent price reductions rather than physical or service-based items that
     * can be "returned" or "refunded" individually.
     *
     * @param Transaction $transaction The parent transaction.
     * @return LineItem[] List of refundable items.
     */
    public function getRefundableLineItems(Transaction $transaction): array
    {
        $refundableItems = [];

        foreach ($transaction->lineItems as $item) {
            // Business Rule: Only positive-value, non-discount items are candidates for manual refund selection.
            if ($item->type !== LineItem::TYPE_DISCOUNT && $item->amountIncludingTax > 0.0) {
                $refundableItems[] = $item;
            }
        }

        return $refundableItems;
    }

    /**
     * Fetches all refunds associated with a specific transaction.
     *
     * @param int $spaceId The identity space.
     * @param int $transactionId The parent transaction ID.
     * @return Refund[] List of existing refunds.
     */
    public function getRefunds(int $spaceId, int $transactionId): array
    {
        return $this->gateway->findByTransaction($spaceId, $transactionId);
    }

    /**
     * Helper to find a specific line item by its identifier.
     *
     * @param LineItem[] $lineItems Search space.
     * @param string $uniqueId Target ID.
     * @return LineItem|null The found item or null.
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
     * Validates that the refund request is mathematically and logically sound.
     *
     * It checks both the global remaining amount and the per-item reduction consistency.
     *
     * @param Transaction $originalTransaction The source transaction.
     * @param RefundContext $context The request details.
     * @throws InvalidRefundException If any business rule is violated.
     */
    private function validateRefund(Transaction $originalTransaction, RefundContext $context): void
    {
        // Global Amount Validation
        // We ensure the requested refund amount does not exceed what is actually available (Authorized - Refunded).
        $authorizedAmount = $originalTransaction->authorizedAmount ?? 0.0;
        $refundedAmount = $originalTransaction->refundedAmount ?? 0.0;
        $remainingAmount = $authorizedAmount - $refundedAmount;

        if ($context->amount > $remainingAmount) {
            $this->logger->error("Validation failed: Refund amount {$context->amount} exceeds remaining amount $remainingAmount.");
            throw new InvalidRefundException("Refund amount exceeds the remaining authorized amount.");
        }

        // Line Item Consistency Validation
        // If specific line items are provided, their individual reductions must sum up to the total refund amount.
        if (!empty($context->lineItems)) {
            $calculatedTotalReduction = 0.0;

            foreach ($context->lineItems as $refundItem) {
                $uId = $refundItem['uniqueId'];
                $quantity = $refundItem['quantity'];
                $unitPriceReduction = $refundItem['amount'];

                $originalItem = $this->findLineItem($originalTransaction->lineItems, $uId);

                if (!$originalItem) {
                    throw new InvalidRefundException("Line item with Unique ID '$uId' not found in original transaction.");
                }

                // Business Rule: Coupons and Discounts cannot be 'refunded' as standalone items.
                if ($originalItem->type === LineItem::TYPE_DISCOUNT) {
                    throw new InvalidRefundException("Cannot refund line item '{$uId}'. Discounts cannot be refunded.");
                }

                if ($originalItem->amountIncludingTax <= 0.0) {
                    throw new InvalidRefundException("Cannot refund line item '{$uId}'. Items with zero or negative amounts cannot be refunded.");
                }

                // Reduction Path Calculation
                // We determine the unit price to validate the per-item reduction.
                $originalUnitPrice = 0.0;
                if ($originalItem->quantity > 0) {
                    $originalUnitPrice = $originalItem->amountIncludingTax / $originalItem->quantity;
                }

                // We prevent over-refunding a single line item.
                if ($quantity > $originalItem->quantity) {
                    throw new InvalidRefundException("Refund quantity $quantity for item '$uId' exceeds original quantity {$originalItem->quantity}.");
                }

                $remainingQuantity = $originalItem->quantity - $quantity;

                // Combined Reduction Formula:
                // We factor in both fully returned items (at full price) and partial reductions on remaining items.
                $itemTotalReduction = ($quantity * $originalUnitPrice) + ($remainingQuantity * $unitPriceReduction);

                if ($itemTotalReduction > $originalItem->amountIncludingTax + 0.01) {
                    $itemAmount = sprintf("%.2f", $itemTotalReduction);
                    $originalAmount = sprintf("%.2f", $originalItem->amountIncludingTax);
                    throw new InvalidRefundException("Refund amount $itemAmount for item '$uId' exceeds original item amount $originalAmount.");
                }

                $calculatedTotalReduction += $itemTotalReduction;
            }

            // Cross-Validation of Totals
            // The sum of individual item reductions must match the global refund amount (allowing minor rounding epsilon).
            if (abs($calculatedTotalReduction - $context->amount) > 0.01) {
                $providedAmount = sprintf("%.2f", $context->amount);
                $calculatedAmount = sprintf("%.2f", $calculatedTotalReduction);
                throw new InvalidRefundException("Consistency Error: Total provided refund amount ($providedAmount) does not match the sum of line item reductions ($calculatedAmount).");
            }
        }
    }
}
