{{-- Boost guideline. In a distributable package this file lives at resources/boost/guidelines/core.blade.php --}}
# HyperPay

This application integrates HyperPay (OPPWA). Always activate the `hyperpay-integration` skill when
building or changing payment code, and `hyperpay-docs` when you need an endpoint, parameter, result
code or test card. Do not fetch the live docs first — both skills are offline references.

## Standing constraints

- Base URLs: `https://eu-test.oppwa.com` (test), `https://oppwa.com` (production). All requests are
  form-encoded with a bearer token; all responses are JSON.
- **Two entity IDs.** Customer-initiated checkout uses one channel, merchant-initiated recurring
  charges another. Using the checkout entity for an MIT charge is rejected by HyperPay.
- **Result codes are prefix-matched with regex, never compared for equality.** Check pending
  (`000.200.*`) before success, or pending false-matches a success pattern.
- **Webhooks: the checkout identifier is `payload.ndc`, not `payload.id`.**
- **Webhook endpoints always return 2xx within 30 seconds**, including on decryption failure —
  a non-2xx response buys 30 days of retries. Queue the real work.
- **Malformed webhook input must never become a 500.** The body is raw hex or JSON
  (`{"encryptedBody":"…"}`) depending on the portal wrapper setting — resolve it before hex, and
  validate with `ctype_xdigit()` before `hex2bin()`. `hex2bin()` returns `false` on non-hex input,
  and under `declare(strict_types=1)` that `false` becomes a `TypeError` inside `openssl_decrypt()`.
  Bad hex returns 2xx with a diagnostic body.
- Webhook delivery is at-least-once and unordered. Completion must be idempotent.
- A checkout ID expires after 30 minutes or on successful payment. Prune abandoned drafts on the
  same clock.
- `merchantTransactionId` needs at least 8 characters. Amounts use a dot decimal separator with no
  thousands separator.
- `notificationUrl` is deprecated — webhooks are configured in the merchant portal.
- Test-mode parameters (`testMode=EXTERNAL`, `customParameters[3DS2_enrolled]`) must be injected
  from the environment, never written by hand, and gated on **two** signals —
  `config('hyperpay.env') !== 'production' && ! app()->isProduction()` — so a single mistyped env
  var on deploy cannot put test mode on live charges.
