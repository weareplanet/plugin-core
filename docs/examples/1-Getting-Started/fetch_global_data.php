<?php

namespace WeArePlanet\Example;

/**
 * Global Data Example
 *
 * Reads every global reference list the WeArePlanet Portal exposes through the single
 * GlobalDataService facade, and demonstrates the two helpers a shop plugin
 * reaches for most often:
 *
 * - LanguageCollection::findPrimary() to turn a shop's two-letter locale into
 *   the concrete IETF variant the WeArePlanet Portal expects.
 * - CurrencyRoundingService::round() to round an amount to the decimal places
 *   the currency actually uses.
 *
 * None of these lookups is space-scoped: this is data about the WeArePlanet Portal itself,
 * identical for every space. So unlike every other example here, this one needs
 * no Space ID — only the credentials that authenticate the call:
 *
 *   export PLUGINCORE_DEMO_USER_ID=98765
 *   export PLUGINCORE_DEMO_API_SECRET='your-api-secret-key'
 *
 * That is also why it wires its own provider instead of using the shared
 * bootstrap: the shared one demands a Space ID for the transaction-based
 * examples, and this example genuinely does not need one.
 *
 * USAGE:
 * php fetch_global_data.php
 */

use WeArePlanet\PluginCore\Examples\Common\EnvSettingsProvider;
use WeArePlanet\PluginCore\Examples\Common\SimpleLogger;
use WeArePlanet\PluginCore\GlobalData\Currency\CurrencyRoundingService;
use WeArePlanet\PluginCore\GlobalData\Exception\GlobalDataException;
use WeArePlanet\PluginCore\GlobalData\GlobalDataService;
use WeArePlanet\PluginCore\Sdk\SdkProvider;
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV1\GlobalDataGateway;
use WeArePlanet\PluginCore\Settings\Settings;

// 📖 Concept documentation: See docs/1-Getting-Started/GlobalData.md

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/../../../vendor/autoload.php';
// Loaded explicitly because docs/ is not in composer's autoload map, and the
// LoggerInterface must be in place before SimpleLogger implements it.
require_once __DIR__ . '/../../../src/Log/LoggerInterface.php';
require_once __DIR__ . '/../Common/SimpleLogger.php';
require_once __DIR__ . '/../Common/EnvSettingsProvider.php';

// Credentials only — no Space ID. SdkProvider resolves a Space ID on demand, and
// nothing below ever asks for one.
foreach (['PLUGINCORE_DEMO_USER_ID', 'PLUGINCORE_DEMO_API_SECRET'] as $variable) {
    if (!getenv($variable)) {
        fwrite(STDERR, "ERROR: Missing environment variable {$variable}\n");
        exit(1);
    }
}

$logger = new SimpleLogger();
$sdkProvider = new SdkProvider(new Settings(new EnvSettingsProvider()));

// One gateway, one service, five lookups. A shop plugin wires this up once —
// typically in its DI container — and injects GlobalDataService wherever it needs
// reference data.
$globalData = new GlobalDataService(
    new GlobalDataGateway($sdkProvider, $logger),
    $logger,
);

try {
    // -----------------------------------------------------------------
    // 1. Currencies
    // -----------------------------------------------------------------
    $currencies = $globalData->getCurrencies();
    echo "Currencies: " . count($currencies) . "\n";

    foreach (array_slice($currencies->all(), 0, 3) as $currency) {
        echo "  {$currency->currencyCode} — {$currency->name} ({$currency->fractionDigits} decimals)\n";
    }

    // Look one up by its ISO 4217 code, e.g. to validate the shop's configured currency.
    $chf = $currencies->findByCurrencyCode('CHF');
    echo "  CHF supported: " . ($chf !== null ? 'yes' : 'no') . "\n";

    // -----------------------------------------------------------------
    // 2. Languages, and resolving a two-letter locale
    // -----------------------------------------------------------------
    $languages = $globalData->getLanguages();
    echo "\nLanguages: " . count($languages) . "\n";

    // A shop usually stores 'en'; the WeArePlanet Portal expects a concrete variant such as
    // 'en-US'. findPrimary() picks the variant flagged as primary for that code.
    foreach (['en', 'de', 'fr'] as $iso2Code) {
        $primary = $languages->findPrimary($iso2Code);
        echo "  {$iso2Code} -> " . ($primary?->ietfCode ?? '(no primary variant)') . "\n";
    }

    // -----------------------------------------------------------------
    // 3. Payment connectors
    // -----------------------------------------------------------------
    $connectors = $globalData->getPaymentConnectors();
    echo "\nPayment connectors: " . count($connectors) . "\n";

    foreach (array_slice($connectors->all(), 0, 3) as $connector) {
        $name = $connector->name->localize('en-US') ?? $connector->name->getDefault();
        $deprecated = $connector->deprecated ? ' [deprecated]' : '';
        echo "  #{$connector->id} {$name}{$deprecated}\n";
        echo "      payment method: " . ($connector->paymentMethodId ?? '(none)')
            . ", processor: " . ($connector->processorId ?? '(none)') . "\n";
    }

    // -----------------------------------------------------------------
    // 4. Label descriptors and their groups
    // -----------------------------------------------------------------
    // These two resolve the numeric IDs on a charge attempt's labels into names
    // a merchant can read. Fetch both once, then look up by ID as needed.
    $descriptors = $globalData->getLabelDescriptors();
    $groups = $globalData->getLabelDescriptorGroups();

    echo "\nLabel descriptors: " . count($descriptors) . " in " . count($groups) . " group(s)\n";

    foreach (array_slice($descriptors->all(), 0, 5) as $descriptor) {
        $name = $descriptor->name->localize('en-US') ?? $descriptor->name->getDefault();
        $groupName = $descriptor->groupId !== null
            ? ($groups->findById($descriptor->groupId)?->name->localize('en-US') ?? '(unknown group)')
            : '(ungrouped)';

        echo "  #{$descriptor->id} {$name} — group: {$groupName}\n";
    }

    // This is the lookup a shop performs when rendering a charge attempt's labels:
    //
    //   foreach ($chargeAttempt->labels as $label) {
    //       $descriptor = $descriptors->findById($label->descriptorId);
    //       echo ($descriptor?->name->localize('en-US') ?? $label->descriptorId)
    //           . ': ' . $label->content;
    //   }

    // -----------------------------------------------------------------
    // 5. Currency-correct rounding
    // -----------------------------------------------------------------
    // CurrencyRoundingService is static and needs no API call — it lives in the
    // same namespace as the Currency entity because it answers the same question:
    // how many decimals does this currency actually use?
    echo "\nRounding 1500.756 / 10.1256 / 10.126 by currency:\n";

    foreach (['JPY' => 1500.756, 'KWD' => 10.1256, 'EUR' => 10.126] as $currencyCode => $amount) {
        $decimals = CurrencyRoundingService::decimalsFor($currencyCode);
        $rounded = CurrencyRoundingService::round($amount, $currencyCode);
        echo "  {$currencyCode} ({$decimals} decimals): {$amount} -> {$rounded}\n";
    }

    // Amounts should be compared through the service too, so a difference below
    // the currency's smallest unit is not mistaken for a real mismatch.
    $equal = CurrencyRoundingService::areAmountsEqual(10.1261, 10.1259, 'KWD');
    echo "  KWD 10.1261 == 10.1259 at 3 decimals: " . ($equal ? 'yes' : 'no') . "\n";
} catch (GlobalDataException $e) {
    // One exception type covers all five lookups.
    echo "\n[FAILED] " . $e->getMessage() . "\n";
    echo "Localized: " . $e->getLocalizedMessage()->localize('en-US') . "\n";
    echo $e->isRetryable() ? "This failure looks transient — retrying may help.\n" : "This failure is terminal.\n";
    exit(1);
}

echo "\nDone.\n";
