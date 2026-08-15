# Laravel HyperPay

HyperPay (OPPWA) knowledge for AI agents working in Laravel applications.

HyperPay is not part of the Laravel ecosystem, so [Laravel Boost](https://github.com/laravel/boost)'s
hosted `search-docs` index does not cover it — agents fall back to guessing endpoint shapes or
fetching the live docs mid-task. This package fills that gap: install it and Boost picks up a set of
always-on guidelines plus two on-demand skills, so the agent already knows the API before it starts
writing.

## Install

```bash
composer require --dev osama-98/laravel-hyperpay
php artisan boost:install
```

Boost discovers `resources/boost/guidelines` and `resources/boost/skills` automatically and syncs
them to your configured agents. Run `boost:update` after upgrading the package.

## What you get

### Guidelines (always in context)

`core.blade.php` — the short list of constraints that are expensive to get wrong: base URLs, the two
separate entity IDs, prefix-matched result codes, `ndc` vs `id` on webhooks, the always-2xx-in-30s
webhook rule, checkout expiry, and how test mode must be injected.

### Skills (loaded on demand)

| Skill | Purpose |
|-------|---------|
| `hyperpay-integration` | How to build the integration in Laravel: config layout, layering, the three flows, testing |
| `hyperpay-docs` | API reference — endpoints, parameters, result codes, standing instructions, webhooks, widget options, test cards |

`hyperpay-docs` is a router plus ten reference files, so a lookup pulls only the relevant page:

```
references/parameters.md       Every request and response parameter
references/tokenization.md     Registration tokens, token types, card-on-file matrix
references/backoffice.md       Capture, refund, reversal, payout, rebill, legal transitions
references/subscriptions.md    Scheduling API, cron fields, merchant advice codes
references/webhooks.md         Decryption, payload shapes, retries, real payloads
references/result-codes.md     Full taxonomy with regex patterns and required action
references/widget.md           COPYandPAY integration and wpwlOptions
references/testing.md          Test cards and 3DS test scenarios
references/payment-methods.md  Brand capability matrix
references/doc-index.md        Canonical URL map of all 94 docs pages
```

## Scope

Documentation and agent guidance only — no runtime code, no service provider, nothing to configure.
Your application keeps full control of how it talks to HyperPay.

Sourced from <https://hyperpay.docs.oppwa.com>. HyperPay and OPPWA are trademarks of their
respective owners; this package is not affiliated with or endorsed by them, ACI Worldwide, or Laravel.

## License

MIT. See [LICENSE](LICENSE).
