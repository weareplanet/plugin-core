<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\PaymentMethod;

enum PaymentMethodSorting: string
{
    case DEFAULT = 'default';
    case NAME = 'name';
}
