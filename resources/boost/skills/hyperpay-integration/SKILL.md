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
// config/hyperpay.php
return [
    'env' => env('HYPERPAY_ENV', 'testing'),
    'url' => env('HYPERPAY_URL', 'https://eu-test.oppwa.com'),
    'access_token' => env('HYPERPAY_ACCESS_TOKEN'),
    'entity_id' => env('HYPERPAY_ENTITY_ID'),
    'recurring_entity_id' => env('HYPERPAY_RECURRING_ENTITY_ID'),
    'integrity_enabled' => env('HYPERPAY_INTEGRITY_ENABLED', true),
    'default_currency' => env('HYPERPAY_DEFAULT_CURRENCY', 'SAR'),
    'webhook_secret' => env('HYPERPAY_WEBHOOK_SECRET'),
];
```

Read values as `config('hyperpay.url')`, `config('hyperpay.entity_id')`, and so on.

**Two entity IDs are not optional.** HyperPay issues a separate channel for customer-initiated
checkout and for merchant-initiated recurring charges. Sending the checkout entity on an MIT charge
is rejected.

Guard the environment flag with **two independent signals**, so one mistyped variable on deploy is
not enough to put `testMode=EXTERNAL` on live charges:

```php
protected function isTestMode(): bool
{
    return config('hyperpay.env') !== 'production' && ! app()->isProduction();
}

// in the payload builder
if ($this->isTestMode()) {
    $payload['testMode'] = 'EXTERNAL';
    $payload['customParameters[3DS2_enrolled]'] = 'true';
}
```

`config('hyperpay.env')` covers a HyperPay-specific override; `app()->isProduction()` reads
`APP_ENV`, which is already correct on every live host. Both must agree before test parameters are
injected — and both default to the safe side, since an unset `HYPERPAY_ENV` yields `testing` only
where `APP_ENV` is also non-production.

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
$response = Http::baseUrl(config('hyperpay.url'))
    ->withToken(config('hyperpay.access_token'))
    ->asForm()
    ->post('v1/checkouts', $payload);
```

Payload essentials for a checkout that also saves the card:

```php
'entityId'                                    => config('hyperpay.entity_id'),
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
'entityId'                                    => config('hyperpay.recurring_entity_id'),
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
        body: $request->getContent(),   // raw hex, or JSON with an `encryptedBody` key
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

The portal's wrapper setting decides whether the body is raw hex or JSON, and both shapes arrive at
the same route. Resolve the ciphertext first, then validate every hex string before converting it.

```php
$json      = json_decode($body, true);
$hexCipher = is_array($json) && isset($json['encryptedBody']) ? $json['encryptedBody'] : $body;

$bin = static fn (string $hex): ?string =>
    $hex !== '' && strlen($hex) % 2 === 0 && ctype_xdigit($hex) ? hex2bin($hex) : null;

$cipher  = $bin($hexCipher);
$key     = $bin(config('hyperpay.webhook_secret')); // 64 hex chars → 32 bytes
$iv      = $bin($hexIv);
$authTag = $bin($hexAuthTag);

if ($cipher === null || $key === null || $iv === null || $authTag === null) {
    return null;   // caller answers 2xx with a diagnostic body — never a 500
}

$decrypted = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $authTag);
```

**Do not feed `hex2bin()` directly into `openssl_decrypt()`.** `hex2bin()` returns `false` on
non-hex input, and under `declare(strict_types=1)` passing `false` to a `string` parameter throws a
`TypeError` — the endpoint returns **500** and earns 30 days of retries for a delivery that can
never succeed. A JSON-wrapped delivery is exactly such an input: its body is JSON, not hex.
Malformed input must return 2xx, never a 500.

A `false` return from `openssl_decrypt` means a wrong key or a tampered body — log and drop it,
never retry, and still answer 2xx.

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
  headers, so the decryptor is covered rather than bypassed. Cover at least:
  - a raw-hex delivery and a JSON-wrapped `{"encryptedBody": …}` delivery — both must return 2xx
  - a non-hex body and non-hex headers — 2xx, never a 500 (this is the `strict_types` `TypeError`)
  - the PENDING webhook that precedes SUCCESS — must not fulfill and must not store a card
  - a redelivered SUCCESS — one transaction, one stored card, one entitlement
- Assert the payload builder omits `testMode` when `APP_ENV=production`, even with
  `HYPERPAY_ENV` mistyped to something non-production.
