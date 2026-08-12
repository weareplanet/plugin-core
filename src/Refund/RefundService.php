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

#[LogContext(domain: 'refund')]
class RefundService
{
    use DomainLoggerTrait;
    public function __construct(
        private readonly RefundGatewayInterface $gateway,
        private readonly TransactionService $transactionService,
        LoggerInterface $logger,
    ) {
        $this->initializeLogger($logger);
    }

    /**
     * Creates a refund for a transaction.
     *
     * When building the RefundContext for a partial, line-item-level refund,
     * source the line items from {@see getRefundableLineItems()} — not the
     * original Transaction cart — and read each item's `unitPriceIncludingTax`
     * for the per-unit price used in the reduction. Never derive it by
     * dividing `amountIncludingTax` by `quantity`: floating-point division
     * introduces rounding errors that cause the gateway API to reject the
     * refund.
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
     * @param int $spaceId
     * @param int $transactionId
     * @return LineItemCollection
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

    public function getRefunds(int $spaceId, int $transactionId): RefundCollection
    {
        return $this->gateway->findByTransaction($spaceId, $transactionId);
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

                // If refund quantity exceeds original, that's an error.
                if ($quantity > $originalItem->quantity) {
                    throw new InvalidRefundException(
                        "Refund quantity $quantity for item '$uId' exceeds original quantity {$originalItem->quantity} in transaction {$originalTransaction->id}.",
                        new LocalizedString("Refund quantity exceeds original quantity."),
                    );
                }

                // Calculate implied reduction for this item. RefundCalculator reads the
                // unit price directly from the line item — never derive it via division
                // (amountIncludingTax / quantity), which introduces floating-point rounding errors.
                $itemTotalReduction = RefundCalculator::calculateReduction($originalItem, $refundItem);

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
