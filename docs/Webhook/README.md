# Webhook Documentation

This directory contains documentation and examples for working with webhooks in the WeArePlanet Integration.

## Modules

### [Webhook Processor](Processor/README.md)

Contains the core logic for **processing incoming webhooks** in your shop plugin. This is what you implementation to handle state changes, locking, and business logic (Commands).

- [Integration Guide](Processor/README.md)
- [Architecture & Concurrency Control](Processor/ARCHITECTURE.md)
- [Simulation Example](Processor/example/)

### [Webhook Management](Management/README.md)

Contains the tools for **programmatically managing** webhooks in the Portal. This module handles URL creation, Listener setup, and uninstallation.

- [Management Guide](Management/README.md)
- [Lifecycle Demo (CLI)](Management/example/webhook.php)
