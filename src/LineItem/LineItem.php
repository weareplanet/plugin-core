<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\LineItem;

use WeArePlanet\PluginCore\Render\JsonStringableTrait;
use WeArePlanet\PluginCore\Tax\Tax;

class LineItem
{
    use JsonStringableTrait;
    public const TYPE_DISCOUNT = 'DISCOUNT';
    public const TYPE_FEE = 'FEE';

    // We define our own constants to avoid leaking SDK dependencies
    public const TYPE_PRODUCT = 'PRODUCT';
    public const TYPE_SHIPPING = 'SHIPPING';

    /** * @var float The total line amount including tax
     */
    public float $amountIncludingTax;

    /**
     * @var array<string, string> Custom attributes map
     */
    public array $attributes = [];
    public string $name;
    public float $quantity;
    public bool $shippingRequired = true;
    public string $sku;

    /**
     * @var list<Tax> List of taxes applied to this item
     */
    private array $taxes = [];

    public string $type = self::TYPE_PRODUCT;

    public string $uniqueId;

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
     * @return Tax[]
     */
    public function getTaxes(): array
    {
        return $this->taxes;
    }
}
