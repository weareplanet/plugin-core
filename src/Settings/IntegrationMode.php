<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Settings;

enum IntegrationMode: string
{
    case IFRAME = 'iframe';
    case LIGHTBOX = 'lightbox';
    case PAYMENT_PAGE = 'payment_page';
}
