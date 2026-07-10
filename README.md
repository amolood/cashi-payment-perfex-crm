# Cashi Payment Gateway for Perfex CRM

A payment gateway module that lets clients pay Perfex CRM invoices via [Cashi](https://www.getcashi.com/) (CashiPay) — a Sudanese QR-based mobile payment provider settling in SDG.

**Requires:** Perfex CRM 2.3.* or later.

---

## How it works

Instead of redirecting clients to an external Cashi-hosted checkout page, this module renders its own in-app payment screen inside Perfex:

1. The client selects **Cashi** as a payment method on an invoice.
2. The module converts the invoice amount into SDG using an admin-configured exchange rate, then creates a payment request via the CashiPay API.
3. The client is shown Cashi's own QR code and reference number directly inside Perfex, along with step-by-step instructions for paying via the **My Cashi** app.
4. The page polls for payment status at a configurable interval and redirects automatically once the payment is confirmed.
5. Cashi's server-to-server webhook and the client-side status check both independently confirm payment before it's recorded — payment status is always re-verified live against the Cashi API rather than trusted from any single source, and a repeated "Pay" click reuses the same pending payment request instead of creating a new one for as long as the invoice amount and reference stay valid.

## Features

- **In-app payment page** — no redirect to an external checkout site; QR code, reference number, and amount are shown directly on your own domain.
- **Multi-currency support** — Cashi always settles in SDG, so every currency configured in your Perfex installation gets its own admin-configurable exchange rate to SDG.
- **Live status polling** — the payment page checks status automatically at a configurable interval, with a manual "Check now" option.
- **Idempotent payment recording** — concurrent confirmations (webhook, status poll, return-URL landing page) cannot double-credit the same invoice.
- **Stable, reusable payment requests** — clicking "Pay" again for the same invoice and amount reuses the existing pending Cashi reference and QR code instead of generating a new one each time.
- **Fully localized** — English and Arabic language files.

## Installation

1. Copy this repository's contents into your Perfex CRM installation at:
   ```
   modules/cashi/
   ```
2. Log in to the admin panel and go to **Setup → Modules**.
3. Activate the **Cashi Gateway** module.
4. Go to **Setup → Payments → Cashi** to configure the gateway (see below).
5. Enable Cashi as an active payment method on the invoice payment settings so it's offered to clients at checkout.

## Configuration

| Setting | Description |
|---|---|
| **Cashi API Base URL** | The CashiPay API endpoint. Defaults to the production URL; change this if Cashi provides a sandbox/staging endpoint. |
| **Cashi API Key** | Your CashiPay API bearer token. Stored encrypted using Perfex's built-in encrypted-settings mechanism. |
| **Exchange rate: 1 `<currency>` = ? SDG** | One field per currency configured in your Perfex installation. Since Cashi always settles in SDG, every invoice currency needs a conversion rate configured here before Cashi can be offered on invoices in that currency. |
| **Status check interval** | How often (in seconds) the payment page polls Cashi for the payment status. |

Currencies without a configured (non-zero) exchange rate will not offer Cashi as a payment option on their invoices.

## License

This module is provided as-is for use with Perfex CRM. See the repository owner for licensing terms.
