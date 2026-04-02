# Checkout Engine Demo

This directory contains a set of scripts to demonstrate the **Checkout Engine** workflow. It simulates a user starting a checkout, modifying their cart, and finally retrieving a payment link.

## Prerequisites

1.  **Dependencies:** Ensure you have run `composer install` in the root of the project.
2.  **Credentials:** You need a WeArePlanet Space ID, User ID, and API Secret.

## Setup

Export your credentials as environment variables in your terminal:

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

## Running the Demo

The demo consists of three sequential steps. Run them in order:

### Step 1: Start Checkout
Creates a fresh transaction for a simulated cart containing one item. It initializes a local `session.json` file to store the Transaction ID, simulating a browser session.

```bash
php 1_start_checkout.php
```

### Step 2: Modify Cart
Simulates a user returning to the shop to change their order. This script loads the ID from session.json and performs multiple updates (changing quantity, adding items, applying discounts) to prove that the Transaction ID remains constant.


```bash
php 2_modify_cart.php
```
### Step 3: Get Payment Link
Retrieves the final Payment Page URL for the updated transaction. Open the resulting link in your browser to see the WeArePlanet payment page.

```bash
php 3_confirm_checkout.php
```