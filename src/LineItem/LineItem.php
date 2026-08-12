<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\LineItem;

use WeArePlanet\PluginCore\Tax\Tax;
use WeArePlanet\PluginCore\SharedKernel\JsonStringableTrait;
use WeArePlanet\PluginCore\SharedKernel\StringSanitizer;

class LineItem
{
    use JsonStringableTrait;

    // We define our own constants to avoid leaking SDK dependencies
    public const TYPE_PRODUCT = 'PRODUCT';
    public const TYPE_DISCOUNT = 'DISCOUNT';
    public const TYPE_SHIPPING = 'SHIPPING';
    public const TYPE_FEE = 'FEE';

    public string $uniqueId;
    public string $sku;
    public string $name;
    public float $quantity;

    /** * @var float The total line amount including tax
     */
    public float $amountIncludingTax;

    /**
     * @var float The unit price including tax, as reported by the API.
     *      Exposed directly so consumers don't need to derive it by dividing
     *      $amountIncludingTax by $quantity, which is prone to rounding
     *      errors that the gateway API rejects on partial refunds.
     */
    public float $unitPriceIncludingTax;

    public string $type = self::TYPE_PRODUCT;
    public bool $shippingRequired = true;

    /**
     * Custom attributes attached to this item. Each {@see LineItemAttribute}
     * carries both the human-readable label and the attribute value so the
     * WeArePlanet Portal can render `label: value` pairs.
     */
    public ?LineItemAttributeCollection $attributes = null;

    /**
     * The discount already netted into $amountIncludingTax, reported
     * separately so the WeArePlanet Portal can display and reconcile it.
     *
     * Compute this as (the item's amount before any discount) minus
     * $amountIncludingTax — e.g. for a Magento quote item,
     * `rowTotalInclTax - amountIncludingTax`, or the discount amount directly
     * for a shipping line. Leave null if the item has no per-item discount
     * (a separate `TYPE_DISCOUNT` line item is a different mechanism — see
     * the Checkout docs).
     */
    public ?float $discountIncludingTax = null;

    /**
     * @var list<Tax> List of taxes applied to this item.
     */
    private array $taxes = [];

    /**
     * Adds a tax to the line item.
     *
     * @param Tax $tax The tax to add.
     */
    public function addTax(Tax $tax): void
    {
        $this->taxes[] = $tax;
    }

    /**
     * @return list<Tax>
     */
    public function getTaxes(): array
    {
        return $this->taxes;
    }

    /**
     * Normalizes this line item in place to satisfy gateway field
     * constraints: truncates `name` and `sku` to the gateway's maximum
     * lengths.
     *
     * Call this after populating the line item and before handing it to a
     * gateway, so oversized shop data never reaches the API.
     */
    public function sanitize(): void
    {
        $this->name = StringSanitizer::truncate($this->name, 150);
        $this->sku = StringSanitizer::truncate($this->sku, 200);
    }
}
