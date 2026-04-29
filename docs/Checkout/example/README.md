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
Creates a fresh transaction for a simulated cart. It initializes a local `session.json` file to store the Transaction ID.

```bash
php 1_start_checkout.php
```

### Step 2: Modify Cart (Optional)
Simulates a user updating their cart (changing quantities, adding items). This demonstrates that the Transaction ID remains constant throughout the session.

```bash
php 2_modify_cart.php
```

### Step 3: Confirm Checkout
You can choose from multiple integration modes to finalize the payment. Each script generates a simulation HTML file that you can open in your browser.

#### A. Payment Page (Redirect)
The simplest integration mode: redirects the user directly to the WeArePlanet payment page.
```bash
php 3_confirm_payment_page.php
```

#### B. IFrame Integration (Standard SSR)
Generates a full HTML block (UI elements + JS) suitable for traditional server-side rendered pages.
```bash
php 3_confirm_iframe.php
```

#### C. Lightbox Integration
Generates a button and the JS logic to trigger a checkout lightbox.
```bash
php 3_confirm_lightbox.php
```

#### D. Custom UI / Reactive Integration (New)
Demonstrates the use of `renderJs()` and `getMetadata()` to build a custom checkout UI, including **CSP nonce** support.
```bash
php 3_confirm_custom_ui.php
```


---

## Testing the Simulations

Due to browser security restrictions (CORS), opening the generated `.html` files via the `file://` protocol will fail. You must run a local web server:

1.  Start the server from the project root:
    ```bash
    php -S localhost:8000
    ```
2.  Open the generated URL provided by the script (e.g., `http://localhost:8000/checkout_simulation_iframe_123.html`).