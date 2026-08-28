<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Transaction;

enum CustomersPresence: string
{
    case NOT_PRESENT = 'NOT_PRESENT';
    case PHYSICAL_PRESENT = 'PHYSICAL_PRESENT';
    case VIRTUAL_PRESENT = 'VIRTUAL_PRESENT';
}
