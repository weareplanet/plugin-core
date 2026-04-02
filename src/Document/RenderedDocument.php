<?php

declare(strict_types=1);

namespace WeArePlanet\PluginCore\Document;

use WeArePlanet\PluginCore\Render\JsonStringableTrait;

/**
 * Domain entity representing a rendered document (PDF).
 */
readonly class RenderedDocument
{
    use JsonStringableTrait;

    /**
     * @param string $title The title of the document.
     * @param string $mimeType The mime type of the document (e.g., application/pdf).
     * @param string $data The binary content of the document.
     */
    public function __construct(
        public string $title,
        public string $mimeType,
        public string $data,
    ) {
    }
}
