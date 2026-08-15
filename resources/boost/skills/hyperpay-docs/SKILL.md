---
name: hyperpay-docs
description: "HyperPay (OPPWA) API reference documentation. Use this skill when you need to look up HyperPay endpoints, API parameters, result codes, standing instruction / card-on-file fields, backoffice operations (capture, refund, reversal), registration tokens, subscriptions and scheduling, webhook payload structure, widget options, or test cards — without fetching the live docs."
license: MIT
metadata:
  author: osama-98
---

# HyperPay API Reference

Source: https://hyperpay.docs.oppwa.com

| Environment | Base URL |
|-------------|----------|
| Test | `https://eu-test.oppwa.com` |
| Production | `https://oppwa.com` |

Auth on every request: `Authorization: Bearer {accessToken}` header, plus `entityId` in the body.
All requests are `application/x-www-form-urlencoded`; all responses are JSON.

## Reference files

Read the file that matches the task — do not load them all.

| File | Covers |
|------|--------|
| `references/parameters.md` | Full request/response parameter reference, every group |
| `references/tokenization.md` | Registration tokens, token types, card-on-file / standing instruction matrix |
| `references/backoffice.md` | Capture, refund, reversal, payout, rebill, chargeback + legal transaction flows |
| `references/subscriptions.md` | Scheduling API, cron `job.*` fields, MAC scheduler, merchant advice codes |
| `references/webhooks.md` | Configuration, AES-256-GCM decryption, payload shapes, retries, real payloads |
| `references/result-codes.md` | Full result-code taxonomy with regex patterns and required action |
| `references/widget.md` | COPYandPAY widget integration and `wpwlOptions` JS API |
| `references/testing.md` | Test cards, `testMode`, 3DS test scenarios |
| `references/payment-methods.md` | Brand capability matrix (VISA, MADA, APPLEPAY, GCC brands, BNPL: VALU / POSTPAY) |
| `references/doc-index.md` | Canonical URL map of all 94 docs pages + scraping notes, for anything not covered above |

## Endpoint map

| Operation | Method + path | Type |
|-----------|---------------|------|
| Prepare checkout (widget) | `POST /v1/checkouts` | — |
| Checkout status | `GET /v1/checkouts/{id}/payment` | — |
| Direct payment (server-to-server) | `POST /v1/payments` | `PA` `DB` `CD` |
| Capture / refund / reversal / rebill | `POST /v1/payments/{id}` | `CP` `RF` `RV` `RB` |
| Create token (standalone) | `POST /v1/registrations` | `RG` |
| Charge a stored token | `POST /v1/registrations/{id}/payments` | `PA` `DB` |
| Delete token | `DELETE /v1/registrations/{id}?entityId=…` | `DR` |
| Extend token retention | `POST /v1/registrations/{id}/extendlife` | `TE` |
| Create schedule | `POST /scheduling/v1/schedules` | `SD` |
| Change schedule | `POST /scheduling/v1/schedules/{id}/reschedule` | `RS` |
| Cancel schedule | `POST /scheduling/v1/schedules/{id}/deschedule` | `DS` |
| List schedules for a token | `GET /scheduling/v1/schedules/{registrationId}` | — |

## Payment types

| Code | Meaning | Code | Meaning |
|------|---------|------|---------|
| `PA` | Pre-authorization | `RF` | Refund |
| `DB` | Debit | `RV` | Reversal (uncaptured `PA` only) |
| `CD` | Credit / payout | `RB` | Rebill |
| `CP` | Capture | `CB` / `CR` | Chargeback / chargeback reversal |
| `RG` | Registration (tokenization) | `DR` / `TE` | Deregister / extend token |
| `SD` / `RS` / `DS` | Schedule / reschedule / deschedule | `3D` / `RE` | Standalone 3DS / standalone risk |

Reversal is only legal on an uncaptured `PA`. Once funds moved (`DB`, `CP`, `CD`), use `RF`.

## COPYandPAY widget — 3 steps

1. `POST /v1/checkouts` server-to-server → `{ "id": "…", "integrity": "…" }`.
2. Render the widget:
   ```html
   <script src="{baseUrl}/v1/paymentWidgets.js?checkoutId={checkoutId}"
           integrity="{integrity}" crossorigin="anonymous"></script>
   <form action="{shopperResultUrl}" class="paymentWidgets" data-brands="VISA MASTER AMEX"></form>
   ```
3. HyperPay redirects to `shopperResultUrl?resourcePath=/v1/checkouts/{checkoutId}/payment`.
   `GET {baseUrl}{resourcePath}?entityId=…` and read `result.code`.

Checkout IDs expire after **30 minutes** or on successful payment.
Status polling is throttled to **2 GET requests per checkout per minute** — prefer webhooks.

## Non-obvious traps

- **`ndc` is not `id`.** Webhook payloads carry both; `payload.ndc` is the checkout-scoped identifier, `payload.id` is HyperPay's transaction ID. See `references/webhooks.md`.
- **Separate `entityId` per channel.** Checkout and recurring/MIT charges normally use different entity IDs. Sending the checkout entity on an MIT charge fails.
- **`notificationUrl` is deprecated.** Configure webhooks in the merchant portal instead.
- **`merchantTransactionId` needs ≥ 8 characters.**
- **Amounts** use a dot decimal separator and no thousands separator (`92.00`).
- **Result codes are prefix-matched**, never compared for equality. Use the regex patterns in `references/result-codes.md`.
- **Webhooks are unordered and may repeat.** Deduplicate, and never treat arrival order as state order.
