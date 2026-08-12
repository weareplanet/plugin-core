<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Refund;

use WeArePlanet\PluginCore\LineItem\LineItem;
use WeArePlanet\PluginCore\LineItem\LineItemCollection;
use WeArePlanet\PluginCore\Localization\LocalizedString;
use WeArePlanet\PluginCore\Log\DomainLoggerTrait;
use WeArePlanet\PluginCore\Log\LogContext;
use WeArePlanet\PluginCore\Log\LoggerInterface;
use WeArePlanet\PluginCore\Refund\Exception\InvalidRefundException;
use WeArePlanet\PluginCore\Refund\Exception\RefundException;
use WeArePlanet\PluginCore\Transaction\Transaction;
use WeArePlanet\PluginCore\Transaction\TransactionService;

/**
 * Manages the processing and validation of refund requests.
 *
 * This service ensuring that refunds follow business rules: they must not exceed
 * the original authorized amount, they must be consistent with the line item
 * reductions specified, and they cannot be applied to non-refundable items like discounts.
 */
#[LogContext(domain: 'refund')]
class RefundService
{
    use DomainLoggerTrait;
    /**
     * @param RefundGatewayInterface $gateway Interface to the persistence or API layer for refunds.
     * @param TransactionService $transactionService Used to fetch the parent transaction for validation.
     * @param LoggerInterface $logger The system logger.
     */
    public function __construct(
        private readonly RefundGatewayInterface $gateway,
        private readonly TransactionService $transactionService,
        LoggerInterface $logger,
    ) {
        $this->initializeLogger($logger);
    }

    /**
     * Orchestrates the creation of a new refund.
     *
     * This method validates the refund against the original transaction's state
     * before committing the request to the gateway.
     *
     * When building the RefundContext for a partial, line-item-level refund,
     * source the line items from {@see getRefundableLineItems()} — not the
     * original Transaction cart — and read each item's `unitPriceIncludingTax`
     * for the per-unit price used in the reduction. Never derive it by
     * dividing `amountIncludingTax` by `quantity`: floating-point division
     * introduces rounding errors that cause the gateway API to reject the
     * refund.
     *
     * @param int $spaceId The identity space.
     * @param RefundContext $context The refund details (amount, line items, external ref).
     * @return Refund The persisted refund object.
     * @throws InvalidRefundException If business invariants are violated.
     * @throws \Throwable For system-level failures.
     */
    public function createRefund(int $spaceId, RefundContext $context): Refund
    {
        $this->logger->debug("Starting refund process.", [
            'transactionId' => $context->transactionId,
            'spaceId' => $spaceId,
        ]);

        // Transaction State Retrieval
        $originalTransaction = $this->transactionService->getTransaction($spaceId, $context->transactionId);

        // Business Rule Validation
        $this->validateRefund($originalTransaction, $context);

        // Gateway Execution
        return $this->gateway->refund($spaceId, $context);
    }

    /**
     * Returns the line items that are still refundable for a transaction.
     *
     * The gateway reports the post-refund cart state on each refund
     * ($reducedLineItems), so the most recent SUCCESSFUL refund already
     * describes what remains — no manual math needed. When no successful
     * refund exists yet, the original transaction line items are returned.
     *
     * When constructing a RefundContext for a partial line-item reduction
     * from the items returned here, read `$item->unitPriceIncludingTax` for
     * the per-unit price. Do not derive it via
     * `$item->amountIncludingTax / $item->quantity` — floating-point
     * division introduces rounding errors that cause the gateway API to
     * reject the refund.
     *
     * @param int $spaceId The identity space.
     * @param int $transactionId The parent transaction ID.
     * @return LineItemCollection The line items still available for refunding.
     */
    public function getRefundableLineItems(int $spaceId, int $transactionId): LineItemCollection
    {
        $latestSuccessful = null;
        foreach ($this->gateway->findByTransaction($spaceId, $transactionId) as $refund) {
            if ($refund->state !== State::SUCCESSFUL) {
                continue;
            }
            if ($latestSuccessful === null || $this->isMoreRecent($refund, $latestSuccessful)) {
                $latestSuccessful = $refund;
            }
        }

        if ($latestSuccessful !== null) {
            // If the gateway did not report the reduced state, assume nothing
            // is left rather than overstating what is refundable.
            return $this->filterRefundableItems($latestSuccessful->reducedLineItems ?? new LineItemCollection());
        }

        $transaction = $this->transactionService->getTransaction($spaceId, $transactionId);
        return $this->filterRefundableItems(new LineItemCollection(...$transaction->lineItems));
    }

    /**
     * Filters a collection down to the items that can actually be refunded.
     * Discounts and items with zero or negative amounts are excluded because
     * they represent price reductions rather than refundable items.
     */
    private function filterRefundableItems(LineItemCollection $collection): LineItemCollection
    {
        $refundableItems = [];
        foreach ($collection as $item) {
            if ($item->type !== LineItem::TYPE_DISCOUNT && $item->amountIncludingTax > 0.0) {
                $refundableItems[] = $item;
            }
        }

        return new LineItemCollection(...$refundableItems);
    }

    /**
     * Whether $candidate is a more recent refund than $current, preferring
     * the creation timestamp and falling back to the sequential ID.
     */
    private function isMoreRecent(Refund $candidate, Refund $current): bool
    {
        $candidateTime = $candidate->createdOn?->getTimestamp();
        $currentTime = $current->createdOn?->getTimestamp();
        if ($candidateTime !== null && $currentTime !== null && $candidateTime !== $currentTime) {
            return $candidateTime > $currentTime;
        }

        return $candidate->id > $current->id;
    }

    /**
     * Fetches all refunds associated with a specific transaction.
     *
     * @param int $spaceId The identity space.
     * @param int $transactionId The parent transaction ID.
     * @return RefundCollection List of existing refunds.
     */
    /**
     * Finds a single refund by its own ID.
     *
     * Chiefly used from a webhook handler: a refund notification carries a refund
     * ID but no transaction ID, so this is what resolves the rest of the record.
     *
     * @param int $spaceId The space ID.
     * @param int $refundId The refund ID.
     * @return Refund The refund.
     * @throws RefundException If the refund cannot be read.
     */
    public function findById(int $spaceId, int $refundId): Refund
    {
        return $this->gateway->findById($spaceId, $refundId);
    }

    public function getRefunds(int $spaceId, int $transactionId): RefundCollection
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

        // Line Item Consistency Validation
        // If specific line items are provided, their individual reductions must sum up to the total refund amount.
        if (!$context->lineItems->isEmpty()) {
            $calculatedTotalReduction = 0.0;

            foreach ($context->lineItems as $refundItem) {
                $uId = $refundItem->uniqueId;
                $quantity = $refundItem->returnedQuantity;

                $originalItem = $this->findLineItem($originalTransaction->lineItems, $uId);

                if (!$originalItem) {
                    throw new InvalidRefundException(
                        "Line item with Unique ID '$uId' not found in original transaction {$originalTransaction->id}.",
                        new LocalizedString("Line item with Unique ID '$uId' not found in original transaction."),
                    );
                }

                // Business Rule: Coupons and Discounts cannot be 'refunded' as standalone items.
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

                // We prevent over-refunding a single line item.
                if ($quantity > $originalItem->quantity) {
                    throw new InvalidRefundException(
                        "Refund quantity $quantity for item '$uId' exceeds original quantity {$originalItem->quantity} in transaction {$originalTransaction->id}.",
                        new LocalizedString("Refund quantity exceeds original quantity."),
                    );
                }

                // Reduction Path Calculation
                // RefundCalculator reads the unit price directly from the line item —
                // never derive it via division (amountIncludingTax / quantity), which
                // introduces floating-point rounding errors.
                $itemTotalReduction = RefundCalculator::calculateReduction($originalItem, $refundItem);

                if ($itemTotalReduction > $originalItem->amountIncludingTax + 0.01) {
                    $itemAmount = sprintf("%.2f", $itemTotalReduction);
                    $originalAmount = sprintf("%.2f", $originalItem->amountIncludingTax);
                    throw new InvalidRefundException(
                        "Refund amount $itemAmount for item '$uId' exceeds original item amount $originalAmount in transaction {$originalTransaction->id}.",
                        new LocalizedString("Refund amount exceeds original item amount."),
                    );
                }

                $calculatedTotalReduction += $itemTotalReduction;
            }

            // Cross-Validation of Totals
            // The sum of individual item reductions must match the global refund amount (allowing minor rounding epsilon).
            if (abs($calculatedTotalReduction - $context->amount) > 0.01) {
                $providedAmount = sprintf("%.2f", $context->amount);
                $calculatedAmount = sprintf("%.2f", $calculatedTotalReduction);
                throw new InvalidRefundException(
                    "Consistency Error: Total provided refund amount ($providedAmount) does not match the sum of line item reductions ($calculatedAmount) for transaction {$originalTransaction->id}.",
                    new LocalizedString("Total refund amount does not match the sum of line item reductions."),
                );
            }
        }
    }
}
