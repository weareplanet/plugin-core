# Global Data

The WeArePlanet Portal exposes a handful of lookup lists that describe *what the WeArePlanet Portal itself supports*, rather than anything about a specific merchant or transaction: the currencies and languages it accepts, the payment connectors it can route through, and the label descriptors and groups used to annotate charge attempts and tokens.

This is **global data** — every space sees the same values, so **none of these lookups take a space ID**. That is what separates it from the rest of PluginCore, and why it lives behind one facade instead of five.

A plugin typically needs this data to build a currency or language picker, to validate a shop's configuration against what the WeArePlanet Portal actually accepts, or to resolve the descriptor IDs on a [ChargeAttempt](../2-Checkout-Flow/Charge.md)'s labels into human-readable names.

## Key Components

One service and one gateway interface cover all five entity types:

- **`GlobalDataService`** — the entry point consumers use. Five methods, no space ID:
  - `getCurrencies(): CurrencyCollection`
  - `getLanguages(): LanguageCollection`
  - `getPaymentConnectors(): PaymentConnectorCollection`
  - `getLabelDescriptors(): LabelDescriptorCollection`
  - `getLabelDescriptorGroups(): LabelDescriptorGroupCollection`
- **`GlobalDataGatewayInterface`** — the same five methods, implemented once per API version.

The entities live in a sub-namespace each, under `GlobalData\<SubDomain>`:

| Entity | Namespace | Properties |
|---|---|---|
| `Currency` | `GlobalData\Currency` | `currencyCode`, `fractionDigits`, `name`, `numericCode` |
| `Language` | `GlobalData\Language` | `iso2Code`, `ietfCode`, `iso3Code`, `name`, `countryCode`, `pluralExpression`, `primaryOfGroup` |
| `PaymentConnector` | `GlobalData\PaymentConnector` | `id`, `name`, `paymentMethodId`, `processorId`, `primaryRiskTaker`, `supportedCurrencies`, `supportedCustomersPresences`, `supportedFeatureIds`, `deprecated`, `deprecationReason` |
| `LabelDescriptor` | `GlobalData\LabelDescriptor` | `id`, `name`, `groupId`, `weight`, `category`, `type` |
| `LabelDescriptorGroup` | `GlobalData\LabelDescriptorGroup` | `id`, `name`, `weight` |

Every entity is `readonly`, and every collection extends the same `AbstractCollection` used elsewhere in PluginCore (`count()`, `isEmpty()`, iteration, plus a `findById()`/`findByCurrencyCode()`-style lookup where it makes sense).

`GlobalData\Currency` additionally holds **`CurrencyRoundingService`** — a static helper that needs no API call and no service wiring. It answers the same question as the `Currency` entity (how many decimals does this currency use?), which is why it sits alongside it. See [Currency-correct rounding](#currency-correct-rounding) below.

## Wiring the service

Wire it up once — in your plugin's DI container — and inject `GlobalDataService` wherever reference data is needed:

```php
use WeArePlanet\PluginCore\Sdk\WebServiceAPIV2\GlobalDataGateway;

$globalData = new GlobalDataService(new GlobalDataGateway($sdkProvider, $logger), $logger);
```

Every lookup returns typed `readonly` entities, gives identical results on both API versions, handles pagination for you, and reports failures as a single domain exception.

## Usage

```php
// One service for all five lookups — no space ID anywhere.
$currencies  = $globalData->getCurrencies();
$primary     = $globalData->getLanguages()->findPrimary('en'); // -> 'en-US', or null
$descriptors = $globalData->getLabelDescriptors();
$groups      = $globalData->getLabelDescriptorGroups();
```

👉 **See this in action:** [fetch_global_data.php](../examples/1-Getting-Started/fetch_global_data.php)

### Locale resolution: `LanguageCollection::findPrimary()`

A shop typically stores a two-letter locale (`en`) while the WeArePlanet Portal expects a concrete IETF variant (`en-US`). `findPrimary()` resolves that: it returns the entry sharing the given `iso2Code` whose `primaryOfGroup` is `true`, or `null` if none is marked primary for that code.

This lives on `LanguageCollection`, not on the service, deliberately: it is a pure query over data already read, not a fresh API call, so it works equally well on a collection obtained any other way and needs nothing beyond the collection itself. The same reasoning applies to `findById()`, `findByCurrencyCode()` and `findByGroup()`.

### Why some fields are IDs instead of embedded objects

`PaymentConnector::$paymentMethodId`/`$processorId`/`$supportedFeatureIds` and `LabelDescriptor::$groupId` hold identifiers rather than embedded entities. The underlying APIs disagree on this — one reports a bare ID, the other embeds the whole related entity — and the identifier is the part both always provide. Normalizing upward would mean an extra API call to fetch the missing entity on one API version but not the other, making an otherwise identical read cost differently depending on which API a shop runs on. Resolve the full entity through the corresponding method on the same service when you need it, e.g. `$globalData->getLabelDescriptorGroups()->findById($descriptor->groupId)`.

### Currency-correct rounding

Most ISO 4217 currencies use 2 decimal places, but not all: some (e.g. `JPY`, `KRW`) have no minor unit, others (e.g. `BHD`, `KWD`) use 3. Rounding a JPY amount to 2 decimals invents fractions of a Yen that gateways reject; rounding a KWD amount to 2 silently discards a valid third digit.

`CurrencyRoundingService` handles that. It is static and involves no API call, so it needs neither the service nor a gateway:

```php
CurrencyRoundingService::round(1500.756, 'JPY'); // 1501.0  (0 decimals)
CurrencyRoundingService::round(10.126, 'EUR');   // 10.13   (2 decimals, the default)
CurrencyRoundingService::decimalsFor('KWD');                        // 3
CurrencyRoundingService::areAmountsEqual(10.1261, 10.1259, 'KWD');  // true
```

Compare amounts through `areAmountsEqual()` rather than `==`, so a difference below the currency's smallest unit is not mistaken for a real mismatch.

### Pagination is not your problem

The two API versions differ in how they expose these lists: some endpoints return everything in one response, others only offer paginated search. The gateways absorb that difference — every method here returns the **complete** collection regardless of API version, paging internally where the API requires it.

## No Space ID required

Because none of this data is space-scoped, a consumer that only reads global data never needs a space configured. `SdkProvider` resolves its Space ID on demand rather than at construction, so this is enough to get going:

```bash
export PLUGINCORE_DEMO_USER_ID=98765
export PLUGINCORE_DEMO_API_SECRET='your-api-secret-key'
```

The user ID and secret are still required — they authenticate the request, which every call needs regardless of scoping.

## Example

See [fetch_global_data.php](../examples/1-Getting-Started/fetch_global_data.php) for a complete runnable script that reads all five lists, resolves a locale with `findPrimary()`, and rounds amounts by currency. It is the only example here that runs without a Space ID.

## Errors

All five methods throw `GlobalData\Exception\GlobalDataException` when the lookup cannot be retrieved, e.g. because the API is unreachable or rejects the request. One exception type covers all five: they share a gateway, a failure mode, and a caller response (fall back to cached or configured values, or surface the failure). Like every other PluginCore exception, it exposes `isRetryable()` — see [Error Handling](ErrorHandling.md).
