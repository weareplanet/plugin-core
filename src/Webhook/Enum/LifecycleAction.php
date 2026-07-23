<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Webhook\Enum;

/**
 * The shop-lifecycle action a gateway state transition implies, independent
 * of which specific state triggered it.
 *
 * Resolvers (e.g. {@see \WeArePlanet\PluginCore\Webhook\TransactionActionResolver})
 * translate a domain state into one of these cases, so platform plugins
 * never have to reason about individual gateway states themselves.
 */
enum LifecycleAction
{
    /** Payment is authorized; the shop should send the order confirmation. */
    case AUTHORIZE;

    /** Payment failed or was voided; the shop should cancel the order. */
    case CANCEL_ORDER;

    /** Payment is completed/fulfilled; the shop should generate the invoice. */
    case FULFILL;

    /** A tracking-only or intermediate state; no shop action is needed. */
    case IGNORE;
}
