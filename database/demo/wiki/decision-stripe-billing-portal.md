---
name: "decision:stripe-billing-portal"
title: Stripe billing portal for Ledgerline
description: Why Ledgerline uses Stripe's hosted portal instead of its own billing pages.
confidence: high
days_ago: 17
sources: [dec-ll-billing-portal, ll-portal-cancellation, ll-sca-failure, ll-annual-plan, ll-dunning-emails]
related: ["project:ledgerline"]
---
# Stripe billing portal for Ledgerline

[[project:ledgerline|Ledgerline]] sends customers to Stripe's hosted billing portal for plan
changes, card updates, past invoices and cancellation. Building those pages would
have taken about two weeks, and keeping them correct as Stripe's rules change would
have taken more.

## Why
- About two weeks of work avoided for a product with a few dozen customers.
- Card updates and payment confirmations follow Stripe's current rules without
  Ledgerline code.
- Annual plans needed no new pages; they appeared in the portal as a switch.

## Costs
- The portal looks like Stripe, not Ledgerline.
- The app learns about portal changes only through webhooks. Cancellations arrive
  as customer.subscription.updated with cancel_at_period_end, and the app shows a
  banner until the period ends.

## Results so far
- Of six failed renewals in the last 30 days, four were recovered through retries
  and the portal's update-card link.
- Four EU renewals failed with authentication_required because the confirmation
  emails were switched off. They are on now; three of the four then paid.

## Timeline
- **63 days ago:** decided.
- **61 days ago:** portal live, with period-end cancellations.
- **45 days ago:** turned on payment confirmation emails for EU cards.
- **22 days ago:** annual plans added through the portal.
- **18 days ago:** four of six failed renewals recovered.
