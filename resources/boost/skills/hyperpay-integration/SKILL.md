---
name: hyperpay-integration
description: "Build a HyperPay (OPPWA) payment integration in a Laravel application: prepare-checkout and the COPYandPAY widget, verifying the result, saving cards as registration tokens, merchant-initiated charges for subscriptions and installments, and the encrypted webhook. Activate when adding HyperPay to a Laravel app, or when the user mentions HyperPay, COPYandPAY, oppwa, registration token, standing instruction, MIT charge, or a HyperPay webhook."
license: MIT
metadata:
  author: osama-98
---

# HyperPay Integration for Laravel

Vendor-neutral build guide. For API facts — every parameter, result code, endpoint, test card —
use the `hyperpay-docs` skill.

## Configuration

Put HyperPay under its own config file rather than `config/services.php`; the integration needs
enough keys that it earns one.

```php
// config/payments.php
return [
    'hyperpay' => [
        'env' => env('HYPERPAY_ENV', 'testing'),
        'url' => env('HYPERPAY_URL', 'https://eu-test.oppwa.com'),
        'access_token' => env('HYPERPAY_ACCESS_TOKEN'),
        'entity_id' => env('HYPERPAY_ENTITY_ID'),
        'recurring_entity_id' => env('HYPERPAY_RECURRING_ENTITY_ID'),
        'integrity_enabled' => env('HYPERPAY_INTEGRITY_ENABLED', true),
        'default_currency' => env('HYPERPAY_DEFAULT_CURRENCY', 'SAR'),
        'webhook_secret' => env('HYPERPAY_WEBHOOK_SECRET'),
    ],
];
```

**Two entity IDs are not optional.** HyperPay issues a separate channel for customer-initiated
checkout and for merchant-initiated recurring charges. Sending the checkout entity on an MIT charge
is rejected.

Guard the environment flag: anything other than `production` should inject `testMode=EXTERNAL`, and
that injection must be impossible to leak into live traffic.

## Layering

Keep four responsibilities apart. Collapsing them is what makes payment code unmaintainable.

| Layer | Owns |
|-------|------|
| HTTP client | Talking to `oppwa.com`. No business rules |
| Checkout orchestration | Pricing, the transaction record, auditing, the API response envelope |
| Gateway adapter | Assembling this provider's payload |
| Completion service | Turning a result into fulfillment, exactly once |

Split payload assembly from sending. The orchestrator can then audit a failed request together with
the payload that caused it — otherwise a connection failure leaves you with no record of what you
sent.

If a second provider is even plausible, put a `CheckoutGateway` interface between the orchestrator
and the adapter, resolve implementations from a tagged container binding, and let a registry map an
enum to an implementation. Adding a provider then costs one class.

## Flow 1 — customer-initiated checkout

### Prepare

```php
$response = Http::baseUrl(config('payments.hyperpay.url'))
    ->withToken(config('payments.hyperpay.access_token'))
    ->asForm()
    ->post('v1/checkouts', $payload);
```

Payload essentials for a checkout that also saves the card:

```php
'entityId'                                    => config('payments.hyperpay.entity_id'),
'amount'                                      => number_format($amount, 2, thousands_separator: ''),
'currency'                                    => $currency,
'paymentType'                                 => 'DB',
'merchantTransactionId'                       => str_pad((string) $id, 8, '0', STR_PAD_LEFT),
'integrity'                                   => true,
'createRegistration'                          => true,
'standingInstruction.mode'                    => 'INITIAL',
'standingInstruction.source'                  => 'CIT',
'standingInstruction.type'                    => 'RECURRING',
'standingInstruction.recurringType'           => 'STANDING_ORDER',
'standingInstruction.expiry'                  => now()->addYears(3)->toDateString(),
'customParameters[recurringPaymentAgreement]' => Str::random(12),
```

Store the generated agreement string — the same value must be replayed on every later MIT charge.

Treat only SUCCESS and PENDING result codes as a usable checkout; anything else is a failure to
open, not a failed payment.

### Render

Return the `id` and `integrity` hash to the frontend. Widget markup and `wpwlOptions` are in
`hyperpay-docs` → `references/widget.md`.

### Complete

The shopper returns to `shopperResultUrl?resourcePath=/v1/checkouts/{id}/payment`.
`GET` that path with `entityId` in the query, map the result code, and fulfill.

Checkout IDs expire after 30 minutes — prune abandoned draft transactions on the same clock.

## Flow 2 — merchant-initiated charges

`POST /v1/registrations/{registrationId}/payments` with the **recurring** entity ID:

```php
'entityId'                                    => config('payments.hyperpay.recurring_entity_id'),
'amount'                                      => $amount,
'currency'                                    => $currency,
'paymentType'                                 => 'DB',
'paymentBrand'                                => $card->payment_brand,
'standingInstruction.mode'                    => 'REPEATED',
'standingInstruction.source'                  => 'MIT',
'standingInstruction.type'                    => 'RECURRING',
'standingInstruction.initialTransactionId'    => $card->initial_transaction_id,
'customParameters[recurringPaymentAgreement]' => $card->recurring_agreement,
```

Persist three values from the initial CIT response, or the card is not chargeable:

| Stored value | Source on the CIT response |
|--------------|---------------------------|
| `registration_id` | `registrationId` |
| `initial_transaction_id` | `standingInstruction.initialTransactionId` (also `resultDetails.CardholderInitiatedTransactionID`) |
| `recurring_agreement` | `customParameters.recurringPaymentAgreement` |

Skip such a card rather than charging it — a rejected MIT can carry scheme fees, and the merchant
advice code that comes back may block retries for days.

`initialTransactionId` is only present on the **SUCCESS** payload, never on the PENDING one.
Do not persist a card from a pending result.

## Flow 3 — webhook

The webhook is the reliable path; the browser redirect is not. A shopper who closes the tab still
produces a webhook.

### Controller

```php
public function handle(Request $request): Response
{
    $payload = $this->decryptor->decrypt(
        hexBody: $request->getContent(),
        hexIv: $request->header('X-Initialization-Vector', ''),
        hexAuthTag: $request->header('X-Authentication-Tag', ''),
    );

    if (! $payload || ! $payload->isPaymentEvent() || ! $payload->checkoutId) {
        return response()->make('ignored');
    }

    dispatch(new ProcessHyperPayWebhookJob($payload->checkoutId, $payload->body));

    return response()->make("received {$payload->checkoutId}");
}
```

Rules that matter:

- **Always answer 2xx**, including on decryption failure. A non-2xx buys 30 days of retries for
  something that will never succeed. Vary the response body per case so HyperPay's delivery log
  stays diagnosable.
- **Answer within 30 seconds.** Queue the work; never fulfill inline.
- **Exempt the route from CSRF** and do not put auth middleware on it.
- **The checkout identifier is `payload.ndc`**, not `payload.id`. `id` is HyperPay's internal
  transaction ID.
- **Deduplicate.** Delivery is at-least-once and unordered; the same checkout arrives PENDING then
  SUCCESS, sometimes twice. Make completion idempotent — return early when the transaction is
  already successful.

### Decryption

```php
$decrypted = openssl_decrypt(
    hex2bin($hexBody),
    'aes-256-gcm',
    hex2bin(config('payments.hyperpay.webhook_secret')), // 64 hex chars → 32 bytes
    OPENSSL_RAW_DATA,
    hex2bin($hexIv),
    hex2bin($hexAuthTag),
);
```

A `false` return means a wrong key or a tampered body — log and drop it, never retry.
JSON-wrapped webhooks put the ciphertext in `encryptedBody` instead of the raw body.

## Result codes

Never compare result codes for equality; HyperPay adds codes within existing families.
Prefix-match with regex and map to your own status enum. Check PENDING first, or `000.200.*` will
false-match a SUCCESS pattern. Full taxonomy and the recommended patterns:
`hyperpay-docs` → `references/result-codes.md`.

Distinguish at minimum: success, pending, manual review, hard decline, SCA required, retriable
connector error. Collapsing "retriable timeout" into "failed" loses money; collapsing "hard decline"
into "retriable" burns scheme fees.

## Testing

- Point `HYPERPAY_URL` at `https://eu-test.oppwa.com` and use the test cards in
  `hyperpay-docs` → `references/testing.md`.
- `000.100.112` is a **success** in connector test mode.
- Fake the HTTP client (`Http::fake()`) for unit tests; assert on the outgoing payload, which is
  where standing-instruction bugs hide.
- Webhook tests should encrypt a fixture payload with a known key and post it with the two hex
  headers, so the decryptor is covered rather than bypassed.
