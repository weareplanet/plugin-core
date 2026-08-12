# Plugin Core documentation

Welcome to the documentation for the WeArePlanet Plugin Core library. In this documentation, we'll guide you through everything you need to know for integrating PluginCore in your shop system.

The documenation is divided in several chapters. Each chapter has its own landing page. If you are integrating PluginCore from scratch, please work through these chapters in order.

## The Chapters

### [1. Getting Started](1-Getting-Started/README.md)

Install the library, wire it to your WeArePlanet Portal credentials, and learn the two conventions the rest of the documentation assumes: how failures are reported, and how the WeArePlanet Portal's global lookup data works.

**You will finish with** a configured `SdkProvider` and a verified connection to the WeArePlanet Portal.

### [2. Checkout Flow](2-Checkout-Flow/README.md)

The customer-facing half: showing available payment methods, creating a transaction from a cart, rendering the payment form, and optionally saving credentials for later.

**You will finish with** an authorized transaction and, if you asked for one, a reusable token.

### [3. Post-Payment](3-Post-Payment/README.md)

Settling that authorization — capturing or voiding the funds, refunding all or part of it, and retrieving the invoices, packing slips and credit notes the WeArePlanet Portal generates.

**You will finish with** a fully settled transaction and the paperwork to back it up.

### [4. Background Tasks](4-Background-Tasks/README.md)

Everything that runs with no customer present: processing the webhooks the WeArePlanet Portal sends you, managing your webhook subscriptions, charging saved tokens for subscriptions, and tracking outstanding merchant tasks.

**You will finish with** an integration that stays correct under retries, concurrency and out-of-order delivery.

---

## Examples

The documentation is filled with running scritp examples. Every runnable script lives under [`examples/`](examples/), grouped by the chapter it belongs to. They all share the same bootstrap ([`examples/Common/bootstrap.php`](examples/Common/bootstrap.php)), which requires a WeArePlanet Space ID, User ID, and API Secret, set as environment variables::

```bash
export PLUGINCORE_DEMO_SPACE_ID=12345
export PLUGINCORE_DEMO_USER_ID=98765
export PLUGINCORE_DEMO_API_SECRET='your-api-secret-key'
```

```fish
set -x PLUGINCORE_DEMO_SPACE_ID 12345
set -x PLUGINCORE_DEMO_USER_ID 98765
set -x PLUGINCORE_DEMO_API_SECRET 'your-api-secret-key'
```

Without these, any example script exits immediately with a "Missing environment variable" error. Set them once per terminal session, then run any example from its own directory.

Two chapter-1 examples need less than that. [Global Data](1-Getting-Started/GlobalData.md) is not space-scoped, so its example needs only `PLUGINCORE_DEMO_USER_ID` and `PLUGINCORE_DEMO_API_SECRET` and wires its own provider rather than using the shared bootstrap. [Error Handling](1-Getting-Started/ErrorHandling.md) is pure domain logic and makes no API call at all, so its example runs with no configuration whatsoever.

[`examples/Common/`](examples/Common/) holds the shared bootstrap and helper classes (logger, settings provider, file persistence) used by every script. See its [README](examples/Common/README.md) for what each file does.
