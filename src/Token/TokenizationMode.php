<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Token;

/**
 * Controls how tokenization of payment information is applied on a transaction.
 *
 * Maps to the SDK's TokenizationMode constants internally, so clients
 * never need to import SDK classes directly.
 */
enum TokenizationMode: string
{
    /**
     * Allows one-click payment if the customer opts in.
     */
    case ALLOW_ONE_CLICK_PAYMENT = 'ALLOW_ONE_CLICK_PAYMENT';
    /**
     * Forces the creation of a new token during payment.
     * Use this when you need to store payment credentials for future
     * recurring (MIT) charges.
     */
    case FORCE_CREATION = 'FORCE_CREATION';

    /**
     * Forces the creation of a new token and enables one-click payment.
     */
    case FORCE_CREATION_WITH_ONE_CLICK_PAYMENT = 'FORCE_CREATION_WITH_ONE_CLICK_PAYMENT';

    /**
     * Forces the update of an existing token's payment credentials.
     */
    case FORCE_UPDATE = 'FORCE_UPDATE';
}
