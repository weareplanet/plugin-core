# Chapter 1: Getting Started

Everything you need before your first API call: installing the library, wiring it to your WeArePlanet Portal credentials, and learning the two conventions the rest of the documentation assumes you know.

Read this chapter in order — the later chapters take all of it for granted.

## Contents

### Initial Setup

- **[Installation](Installation.md)** — Requirements, the Composer install, and wiring `SdkProvider` with your credentials.
- **[Plugin Identification](PluginIdentification.md)** — The four headers naming your shop system and plugin version, which make a support case traceable back to a concrete installation.

### Core Concepts

- **[Error Handling](ErrorHandling.md)** — Two cross-cutting patterns used throughout PluginCore: knowing whether a failure is worth retrying, and checking what a gateway state actually means.
- **[Global Data](GlobalData.md)** — The WeArePlanet Portal's own lookup lists (currencies, languages, payment connectors, label descriptors), none of which take a space ID.

## Examples

Runnable scripts for this chapter live in [`examples/1-Getting-Started/`](../examples/1-Getting-Started/). See [Running the Examples](../README.md#examples) for the environment variables they need.

---

[← Documentation index](../README.md) · [Chapter 2: Checkout Flow →](../2-Checkout-Flow/README.md)
