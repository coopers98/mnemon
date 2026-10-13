---
name: "person:rowan"
title: Rowan Vale
description: The developer behind Tidepool, Ledgerline and Quarry, and how they like to work.
confidence: high
days_ago: 4
sources: [rowan-commit-style, rowan-boring-tech, rowan-one-vps, rowan-no-docker, rowan-tests, rowan-cabin-internet, rowan-tomas-ops, rowan-morning-hours, rowan-paddle-season, rowan-weekly-review, rowan-focus-decision, rowan-error-messages]
related: ["project:tidepool", "project:ledgerline", "project:quarry", "concept:boring-tech-budget"]
revisions:
  - days_ago: 50
    content: |
      # Rowan Vale

      Rowan Vale is a solo developer who builds small products from a cabin on a
      satellite connection. Prefers boring technology and runs everything on one VPS.

      ## Key facts
      - One VPS (4 vCPU, 8 GB, $24/month) runs all three products.
      - Satellite internet at ~600 ms latency; deploys are a single rsync and restart.
      - Tomás Ferreira covers operations during paddling trips.

      ## Preferences
      - Commit messages: lower case, imperative, one line.
      - No Docker in production; systemd units instead.
      - Tests for money, time zones and dates; UI is checked by hand.
  - days_ago: 25
    content: |
      # Rowan Vale

      Rowan Vale is a solo developer who runs three small products (Tidepool,
      Ledgerline and Quarry) from a cabin on a satellite connection. Prefers boring
      technology, keeps everything on one VPS, and protects mornings for deep work.

      ## Key facts
      - Deep work 06:00–11:00, no calls before noon.
      - One VPS (4 vCPU, 8 GB, $24/month) behind Caddy; each product is a systemd service.
      - Satellite internet at ~600 ms latency; deploys are a single rsync and restart.
      - Tomás Ferreira covers operations during paddling trips, with a one-page runbook.

      ## Preferences
      - Commit messages: lower case, imperative, one line, no scope prefix.
      - No Docker in production.
      - Tests for money, time zones and dates; UI is checked by hand.

      ## Decisions
      - Ledgerline gets three days a week, since it is the only product that pays.

      ## Timeline
      - **36 days ago:** started a 30-minute Sunday review.
      - **29 days ago:** split the week: Ledgerline three days, Tidepool one.
---
# Rowan Vale

Rowan Vale is a solo developer who runs three small products, [[project:tidepool|Tidepool]],
[[project:ledgerline|Ledgerline]] and [[project:quarry|Quarry]], from a cabin on a satellite
connection. Rowan picks boring technology on purpose, keeps everything on one VPS,
and protects mornings for deep work. Ledgerline is the only product that pays, so
it gets most of the week.

## Key facts
- Deep work 06:00–11:00, no calls before noon; customer email is answered once,
  after lunch.
- The cabin's satellite link runs at ~600 ms latency and drops in heavy rain. It
  shapes the deploy process (one rsync, one restart) and is why Tidepool is
  offline-first.
- One VPS (4 vCPU, 8 GB, $24/month) runs all three products as systemd services
  behind Caddy. No Docker in production.
- Tomás Ferreira covers operations during paddling trips: his own sudo account, the
  uptime alerts, and a runbook kept to one page.
- No deploys on Fridays or the day before a trip.

## Preferences
- Commit messages: lower case, imperative, one line, no scope prefix. The why goes
  in a code comment.
- A new tool has to replace something, not sit beside it. See
  [[concept:boring-tech-budget|boring technology budget]].
- Anything touching money, time zones or dates gets a test before it ships; UI is
  clicked through by hand. No browser tests in Ledgerline.
- Error messages say what to do next ("Add a due date to send this invoice"), not
  what went wrong.

## Decisions
- Ledgerline gets three days a week, Tidepool one until v1.0, Quarry the rest.

## Open items
- Revisit the time split once Tidepool v1.0 is out.

## Timeline
- **87 days ago:** wrote up the one-VPS setup: Caddy in front, one systemd unit per
  product.
- **64 days ago:** Tomás took over on-call during trips.
- **36 days ago:** started a 30-minute Sunday review that ends by dropping one thing.
- **29 days ago:** split the week: Ledgerline three days, Tidepool one.
- **14 days ago:** rewrote Ledgerline's validation messages to say what to do next.
