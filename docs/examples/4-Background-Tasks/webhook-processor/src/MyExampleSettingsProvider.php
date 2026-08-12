<?php

declare(strict_types=1);

namespace MyPlugin\ExampleWebhookImplementation;

use WeArePlanet\PluginCore\Settings\DefaultSettingsProvider;

// 📖 Concept documentation: See docs/4-Background-Tasks/Webhook-Processor.md

/**
 * A dummy Settings Provider for this example.
 */
class MyExampleSettingsProvider extends DefaultSettingsProvider
{
    public function getSpaceId(): ?int { return 12345; }
    public function getUserId(): ?int { return 67890; }
    public function getApiKey(): ?string { return 'dummy-api-key'; }
}
