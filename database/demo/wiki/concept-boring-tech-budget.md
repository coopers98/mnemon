---
name: "concept:boring-tech-budget"
title: Boring technology budget
description: Rowan's rule that each product may spend one new technology, and what has been spent.
confidence: high
days_ago: 11
sources: [rowan-boring-tech, dec-boring-budget, dec-quarry-rust, dec-tp-sqlite, dec-one-vps, rowan-no-docker, rowan-budget, dec-error-tracking, dec-renovate-off]
related: ["decision:sqlite-for-tidepool", "person:rowan"]
revisions:
  - days_ago: 35
    content: |
      # Boring technology budget

      Each product may spend one "new technology". Everything else uses tools Rowan
      already runs: Postgres or SQLite, server-rendered pages, cron, systemd.

      ## Spent so far
      - **Tidepool:** service workers.
      - **Quarry:** Rust.
      - **Ledgerline:** nothing.

      ## Key facts
      - Hosting budget is $60/month across all products.
      - No hosted error tracker: a nightly log digest instead.

      ## Timeline
      - **60 days ago:** the rule written down.
---
# Boring technology budget

[[person:rowan|Rowan]] lets each product spend one "new technology". Everything else
uses tools already running on the VPS: Postgres or SQLite, server-rendered pages,
cron, systemd. A new tool has to replace something, not sit beside it. The rule
was followed for months before it was written down 60 days ago.

## Spent so far
- **Tidepool:** service workers.
- **Quarry:** Rust, for a single static binary and fast startup.
- **Ledgerline:** nothing, and it should stay that way while it pays the bills.

## In practice
- Redis was turned down for Ledgerline's queue; the database queue handles ~300
  jobs a day.
- Tidepool left managed Postgres for SQLite. See [[decision:sqlite-for-tidepool|SQLite for Tidepool]].
- Three VPSs became one, saving $22/month and one set of OS upgrades.
- No Docker in production, and no hosted error tracker. The tracker would have
  cost $26/month, more than the VPS; a nightly log digest replaced it.
- Automated dependency pull requests were switched off (31 in a month) in favour
  of a dependency day on the first Monday of each month.

## Key facts
- Hosting budget is $60/month across all products; currently $31 is spent.

## Open items
- Tidepool's SQLite choice holds only while there is one writer. User accounts
  with synced data would reopen it.

## Timeline
- **90 days ago:** consolidated onto one VPS.
- **74 days ago:** Tidepool moved to SQLite.
- **65 days ago:** chose a log digest over a hosted error tracker.
- **60 days ago:** the rule written down.
- **33 days ago:** dependency bots off; monthly dependency day.
