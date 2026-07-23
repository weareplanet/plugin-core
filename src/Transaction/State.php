<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction;

use WeArePlanet\PluginCore\State\ValidatesStateTransitions;

enum State: string
{
    use ValidatesStateTransitions;

    /**
     * Whether the shop may still create or cancel a local invoice for this transaction.
     *
     * While AUTHORIZED, the portal has not generated its own invoice yet, so
     * the shop's local bookkeeping can still freely create or cancel one.
     * Once COMPLETED, the portal's invoice takes over and local manipulation
     * is no longer safe.
     */
    public function allowsInvoiceManipulation(): bool
    {
        return match ($this) {
            self::AUTHORIZED => true,
            default => false,
        };
    }

    /**
     * The string values of every state for which {@see isPaidLike()} is true.
     *
     * Intended for platform integrations that need to filter/poll by state
     * using raw scalar values (e.g. a database query's `WHERE state IN (...)`
     * clause) without duplicating the AUTHORIZED/COMPLETED/FULFILL grouping.
     *
     * @return list<string>
     */
    public static function getPaidLikeValues(): array
    {
        return array_values(array_map(
            static fn (self $state): string => $state->value,
            array_filter(self::cases(), static fn (self $state): bool => $state->isPaidLike()),
        ));
    }

    public static function getTransitionMap(): array
    {
        return [
            'initial' => [
                self::CREATE->value,
            ],
            'transitions' => [
                self::CREATE->value    => [self::PENDING->value, self::CONFIRMED->value],
                self::PENDING->value    => [self::CONFIRMED->value, self::FAILED->value],
                self::CONFIRMED->value => [self::PROCESSING->value, self::FAILED->value],
                self::PROCESSING->value => [self::FAILED->value, self::AUTHORIZED->value],
                self::AUTHORIZED->value => [self::COMPLETED->value, self::VOIDED->value],
                self::COMPLETED->value  => [self::FULFILL->value, self::DECLINE->value],
            ],
            'final' => [
                self::FAILED->value,
                self::VOIDED->value,
                self::FULFILL->value,
                self::DECLINE->value,
            ],
            'any_to' => [
                self::FAILED->value,
                self::VOIDED->value,
            ],
            'sequence' => [
                self::CREATE->value,
                self::PENDING->value,
                self::CONFIRMED->value,
                self::PROCESSING->value,
                self::AUTHORIZED->value,
                self::COMPLETED->value,
                self::FULFILL->value,
            ],
        ];
    }

    /**
     * Whether the portal has generated an invoice document that can be downloaded.
     *
     * The invoice is generated once the transaction COMPLETES, and it stays
     * downloadable in every state reachable afterward — including DECLINE,
     * which represents a post-completion dispute rather than a missing invoice.
     */
    public function isInvoiceDownloadAllowed(): bool
    {
        return match ($this) {
            self::COMPLETED, self::FULFILL, self::DECLINE => true,
            default => false,
        };
    }

    /**
     * Whether the portal has generated a packing slip that can be downloaded.
     *
     * Unlike the invoice (generated at COMPLETED), the packing slip only
     * exists once the order has actually been fulfilled (shipped).
     */
    public function isPackingSlipDownloadAllowed(): bool
    {
        return match ($this) {
            self::FULFILL => true,
            default => false,
        };
    }

    /**
     * Whether money is secured for this transaction.
     *
     * True from AUTHORIZED onward (AUTHORIZED, COMPLETED, FULFILL): the
     * customer has been charged, or the charge is guaranteed, regardless of
     * whether the portal has invoiced or fulfilled the order yet.
     */
    public function isPaidLike(): bool
    {
        return match ($this) {
            self::AUTHORIZED, self::COMPLETED, self::FULFILL => true,
            default => false,
        };
    }
    case AUTHORIZED = 'AUTHORIZED';
    case COMPLETED = 'COMPLETED';
    case CONFIRMED = 'CONFIRMED';
    case CREATE = 'CREATE';
    case DECLINE = 'DECLINE';
    case FAILED = 'FAILED';
    case FULFILL = 'FULFILL';
    case PENDING = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case VOIDED = 'VOIDED';
}
