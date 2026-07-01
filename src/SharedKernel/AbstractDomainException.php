<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\SharedKernel;

use WeArePlanet\PluginCore\Localization\LocalizedString;

abstract class AbstractDomainException extends \Exception
{
    private LocalizedString $localizedMessage;

    public function __construct(
        string $technicalMessage,
        ?LocalizedString $localizedMessage = null,
        ?\Throwable $previous = null,
    ) {
        $code = $previous !== null ? $previous->getCode() : 0;
        $this->localizedMessage = $localizedMessage ?? new LocalizedString($technicalMessage);
        parent::__construct($technicalMessage, (int)$code, $previous);
    }

    public function getLocalizedMessage(): LocalizedString
    {
        return $this->localizedMessage;
    }
}
