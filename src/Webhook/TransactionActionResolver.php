<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook;

use WeArePlanet\PluginCore\Transaction\State as TransactionState;
use WeArePlanet\PluginCore\Webhook\Enum\LifecycleAction;

/**
 * Translates a {@see TransactionState} into the shop-lifecycle action it
 * implies, so platform plugins never have to reason about individual
 * gateway states themselves.
 */
final class TransactionActionResolver
{
    /**
     * Resolves the shop-lifecycle action implied by a transaction state.
     *
     * - {@see LifecycleAction::AUTHORIZE}: payment is authorized (AUTHORIZED)
     *   — time to send the order confirmation.
     * - {@see LifecycleAction::FULFILL}: payment is completed/fulfilled
     *   (COMPLETED, FULFILL) — time to generate the invoice.
     * - {@see LifecycleAction::CANCEL_ORDER}: payment failed or was voided
     *   (FAILED, VOIDED, DECLINE).
     * - {@see LifecycleAction::IGNORE}: an intermediate or tracking-only
     *   state (CREATE, PENDING, CONFIRMED, PROCESSING) that requires no
     *   shop action.
     *
     * Note: {@see TransactionState::isPaidLike()} groups AUTHORIZED and
     * FULFILL together, which is too coarse here — AUTHORIZED and
     * COMPLETED/FULFILL now map to distinct actions, so this uses an
     * explicit match instead.
     */
    public function resolve(TransactionState $state): LifecycleAction
    {
        return match ($state) {
            TransactionState::AUTHORIZED => LifecycleAction::AUTHORIZE,
            TransactionState::COMPLETED, TransactionState::FULFILL => LifecycleAction::FULFILL,
            TransactionState::FAILED, TransactionState::VOIDED, TransactionState::DECLINE => LifecycleAction::CANCEL_ORDER,
            default => LifecycleAction::IGNORE,
        };
    }
}
