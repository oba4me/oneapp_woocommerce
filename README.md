
# Boldd WooCommerce Checkout (OneApp)

Plugin: Boldd WooCommerce Checkout  
Main file: `oneapp-woocommerce-checkout.php`  
Version: 2.3  
Author: Alexander Bamidele  
License: GPL-2.0

# Description
-----------
Integrates the OneApp / Boldd payment gateway with WooCommerce. Supports inline (popup) and standard (hosted) checkout flows, server-side verification, webhook handling and scheduled verification for pending orders. Uses gateway-provided Reference as the canonical transaction id.

# Quick features
--------------
- Adds "Boldd" (id: `bolddcheckout`) as a WooCommerce payment gateway.
- Inline (JS popup) and Standard (redirect) checkout modes.
- Webhook endpoint to auto-complete orders on successful payment.
- AJAX endpoints for initiating payments and server-side verification.
- Scheduled cron job to verify pending/on‑hold orders.
- REST endpoint exposing the public key: `GET /wp-json/boldd/v1/public-key`.
- Debug logging via WP_DEBUG.

# Requirements
------------
- PHP 7.4+  
- WooCommerce 4.x+  
- Merchant account & API keys at https://useboldd.com (OneApp)
- Currency: NGN, USD, GBP (plugin only available when store currency is NGN, USD, GBP)

# Installation
------------
1. Copy the plugin folder to your WooCommerce plugins directory (wp-content/plugins/oneapp_woocommerce).  
2. Activate the plugin in WordPress Admin → Plugins.  
3. Configure via WooCommerce → Settings → Payments → Boldd Payment.

# Configuration
-------------
Settings available in the gateway admin:
- Public Key
- Secret Key (server-side verification)
- Checkout Mode: `inline` or `standard`
- Dashboard URL (admin quick link)
- Webhook/Callback URL (shown in gateway settings): `https://your-site.example/?boldd_wc_webhook=1`

# Endpoints & AJAX
----------------
- Webhook: POST to site with query `?boldd_wc_webhook=1` (or legacy `oneapp_wc_webhook=1`). Processes payload, verifies, and completes matching order.
- REST: GET /wp-json/boldd/v1/public-key — returns configured public key (permission: public).
- AJAX:
  - Action `boldd_initiate_payment` — initiate standard checkout (POST).
  - Action `boldd_verify_payment` — server-side verify a reference (POST/GET via admin-ajax).
  - Action `oneapp_check_order_status` — poll order status for client-side verification.
  - Action `oneapp_verify_on_redirect` — legacy verify on redirect.

# Cron / Scheduled Verification
-----------------------------
- Adds `boldd_verify_pending_orders` scheduled event (every 5 minutes) on activation.
- On deactivation the scheduled hook is cleared.
- Cron iterates pending/on-hold orders, verifies references with the API and completes orders when payment is confirmed.

# Important implementation notes
------------------------------
- Main gateway class: `WC_Boldd_Gateway` (extends `WC_Payment_Gateway`).
- Key helpers:
  - `verify_reference_with_api($reference, $secret)` — calls OneApp verifytrans API.
  - `initiate_payment_with_api(array $payload, $secret)` — calls OneApp initiatetrans API.
- Order meta keys:
  - `_boldd_initiated_reference` — internal initiated reference used to map redirects.
  - `_boldd_gateway_reference` — canonical gateway reference returned by the gateway.
- The plugin sets transaction id on orders (uses `set_transaction_id` when available).
- Inline flow uses external script: `https://js.oneappgo.com/v1/checkout.js`.

# Debugging & Troubleshooting
---------------------------
- Enable WP_DEBUG to get debug logs via error_log (prefixed with `[Boldd DEBUG]`).
- Check WooCommerce → Status → Logs for related entries.
- Use Sandbox/test credentials and the gateway's sample card data when available.
- Verify webhook URL and that your site accepts POST from the gateway.

# Developer notes
---------------
- Entry file: `oneapp-woocommerce-checkout.php`
- Key hooks used: `woocommerce_payment_gateways`, `woocommerce_receipt_bolddcheckout`, `rest_api_init`, admin-ajax hooks, `init` for webhook handling, scheduled event `boldd_verify_pending_orders`.
- Currency check: plugin limits availability to stores using NGN, USD, GBP.
- Contributions and PRs welcome — follow WordPress PHP coding standards.

# Support
-------
# For integration questions contact: oba4me@gmail.com | alex@1appgo.com | https://github.com/oba4me

# License
-------
GPL‑2.0 — see `LICENSE` in repository for full details.
