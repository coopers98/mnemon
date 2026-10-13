---
name: "project:ledgerline"
title: Ledgerline
description: Invoicing for freelancers; the one product that pays, 41 customers on Stripe billing.
confidence: high
days_ago: 1
sources: [ll-stack, ll-integer-cents, ll-multicurrency, ll-stripe-webhook-dup, dec-ll-billing-portal, ll-customers-41, ll-invoice-number-gap, ll-annual-plan, ll-editor-autosave, ll-priya-editor-mockups, ll-customer-quote-feature, ll-editor-start]
related: ["decision:stripe-billing-portal", "person:priya-nair", "person:rowan"]
revisions:
  - days_ago: 30
    content: |
      # Ledgerline

      Ledgerline is invoicing software for freelancers. It is a Laravel monolith on
      the shared VPS, with Postgres alongside and subscriptions billed through Stripe.
      It is the only one of Rowan's products that makes money.

      ## Key facts
      - 34 paying customers at the last count: 26 Solo ($12/month), 8 Studio ($29/month).
      - Money is stored as integer cents with a currency code; invoices carry their own
        currency, and reports group totals per currency.
      - Stripe's hosted billing portal handles plan changes, cards and cancellation.
      - Stripe webhooks are idempotent on event id after a duplicate invoice.paid.

      ## Design
      Priya Nair designs Ledgerline on contract: the invoice template and the two-step
      onboarding.

      ## Timeline
      - **74 days ago:** multi-currency invoices.
      - **70 days ago:** fixed duplicate receipts from a repeated webhook.
      - **63 days ago:** adopted the Stripe billing portal.
---
# Ledgerline

Ledgerline is invoicing software for freelancers, and the only one of Rowan's
products that pays. It is a Laravel monolith with server-rendered pages and
Postgres on the shared VPS; Stripe bills the subscriptions and Ledgerline never
sees a card number. Current work is the invoice editor rework, designed by
[[person:priya-nair|Priya Nair]].

## Key facts
- 41 paying customers: 30 Solo ($12/month), 11 Studio ($29/month). MRR $679.
- Annual plans at 10× monthly ($120 Solo, $290 Studio).
- Money is stored as integer cents next to a currency code. Invoices carry their
  own currency (EUR, GBP, USD, CAD, AUD), and reports group totals per currency
  instead of converting.
- Invoice numbers are assigned when an invoice is sent, so deleted drafts leave no
  gaps. Some countries require sequential numbering.

## Decisions
- Stripe's hosted billing portal handles plans, cards and cancellation. See
  [[decision:stripe-billing-portal|Stripe billing portal]].
- Webhook handling is idempotent on the Stripe event id.
- Quotes (estimates that convert to invoices) come next quarter, built on the new
  editor. Five customers have asked.

## Open items
- **Invoice editor rework**, started on branch editor-v2 from Priya's mockups. Line items get their own save endpoint. The current autosave sent an
  invoice with stale line items 13 days ago; Send now waits for the save and
  re-reads the invoice.

## Timeline
- **84 days ago:** last decimal money column migrated to integer cents.
- **74 days ago:** multi-currency invoices.
- **70 days ago:** fixed duplicate receipts from a repeated webhook.
- **63 days ago:** adopted the Stripe billing portal.
- **28 days ago:** invoice numbers assigned on send.
- **25 days ago:** 41 paying customers, up from 34.
- **22 days ago:** annual plans launched.
- **13 days ago:** fixed the autosave/Send race.
- **10 days ago:** Priya delivered the editor mockups.
- **1 day ago:** editor rework started.
