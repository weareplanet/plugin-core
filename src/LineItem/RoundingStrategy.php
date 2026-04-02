<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\LineItem;

enum RoundingStrategy: string
{
    /**
     * Standard commercial rounding.
     * Round to 2 decimals at the line item level first, then sum up.
     * This is the default for most systems (Magento, WooCommerce).
     */
    case BY_LINE_ITEM = 'BY_LINE_ITEM';

    /**
     * Sum up all high-precision totals first, then round the final grand total.
     * Often used in B2B systems or custom ERPs.
     */
    case BY_TOTAL = 'BY_TOTAL';

    // We can add specific strategies like 'FLOOR', 'CEIL' if requested later.
}
