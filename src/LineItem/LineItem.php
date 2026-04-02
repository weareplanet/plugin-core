<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\LineItem;

use WeArePlanet\PluginCore\Tax\Tax;
use WeArePlanet\PluginCore\Render\JsonStringableTrait;

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

    public string $type = self::TYPE_PRODUCT;
    public bool $shippingRequired = true;

    /**
     * @var array<string, string> Custom attributes map.
     */
    public array $attributes = [];

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
}
