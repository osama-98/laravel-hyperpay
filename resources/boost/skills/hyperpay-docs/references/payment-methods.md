# Payment Method Capabilities

Source: https://hyperpay.docs.oppwa.com/reference/payment-methods
(the live table is AJAX-rendered and filterable by country and brand type; 133 brands total)

`Flow: Sync` means the result comes back on the API call. `Flow: Async` means the shopper is
redirected and the result arrives via `shopperResultUrl` + webhook.

## Brands relevant to Gulf / card processing

| Brand | Type | Flow | Supported operations |
|-------|------|------|----------------------|
| `VISA` | Credit card | Sync | Pre-auth, reversal, capture (partial/multiple/full), debit, refund (partial/multiple/full/higher), chargeback, credit |
| `MASTER` | Credit card | Sync | Pre-auth, reversal, capture (partial/multiple/full), debit, refund (partial/multiple/full/higher), chargeback, credit |
| `AMEX` | Credit card | Sync | Pre-auth, reversal, capture (partial/multiple/full), debit, refund (partial/multiple/full/higher), credit |
| `MADA` | Debit card | Sync | Pre-auth, reversal, capture (partial/multiple/full), debit, refund (partial/multiple/full), chargeback |
| `MASTERDEBIT` | Debit card | Sync | Debit, refund (**full only**) |
| `APPLEPAY` | Wallet | Sync | Pre-auth, reversal, capture (partial/multiple/full), debit, refund (partial/multiple/full/higher), chargeback, credit |
| `APPLEPAYTKN` | Wallet | Sync | Pre-auth, reversal, capture (partial/full), debit, refund (partial/full) — Egypt, Japan, Jordan, UAE |
| `GOOGLEPAY` | Wallet | Sync | Pre-auth, reversal, capture (partial/multiple/full), debit, refund (partial/multiple/full), chargeback, credit |
| `CLICK_TO_PAY` | Credit card | Sync | Pre-auth, reversal, capture (partial/multiple/full), debit, refund (partial/multiple/full) |
| `CLIQ` | Wallet | Sync | Debit, refund (partial/multiple/full) — Saudi Arabia |
| `KNET` | Virtual | Async | Debit, refund (partial/multiple/full) |
| `OMANNET` | Card | Sync | Pre-auth, debit, refund (partial/multiple/full) |
| `JAYWAN` | Debit card | Sync | Pre-auth, reversal, debit, refund (partial/multiple/full) — UAE |

Watch `MASTERDEBIT`: **full refunds only**, unlike `MASTER`. Partial-refund logic must branch on brand.

Availability is per-acquirer, not just per-brand — the live table lists which providers support each
brand (Al Rajhi Bank ARB Gateway, MPGS, VPC/VISA, N-Genius, …). Confirm with your acquirer before
relying on an operation.

## Full `paymentBrand` value list

```
VISA MASTER AFTERPAY AIRPLUS AMEX APPLEPAY APPLEPAYTKN ARGENCARD AXP CABAL CABALDEBIT CARNET
CARTEBANCAIRE CASEYS_GIFT_CARD CASHLINKMALTA CENCOSUD CLICK_TO_PAY CLIQ COSMOPROF_RCC DANKORT
DIRECTDEBIT_SEPA DISCOVER ELO GOOGLEPAY GOOGLEPAYTKN HEB_GIFT_CARD HIPERCARD JAYWAN JCB LINEPAY
MADA MAESTRO MASTERDEBIT MERCADOLIVRE NARANJA NATIVA OMANNET PAZE PETCO_GIFT_CARD PETCO_MASTERCARD
PETCO_UPLCC PMICHAELS_PLCC POSTPAY PROSTIR RAKUTENPAY RATEPAY_INVOICE SCHEELS SEPA SERVIRED SHOPIFY
STAPLES TARJETASHOPPING TCARD TCARDDEBIT TRADE_UK UNIONPAY VENMO_PAYFAST VISADEBIT VISAELECTRON VPAY
```

This is the brand list offered on the server-to-server payment sample; the full catalogue on the
payment-methods page is larger and includes async local methods.
