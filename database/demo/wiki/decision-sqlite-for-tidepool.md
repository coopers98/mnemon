---
name: "decision:sqlite-for-tidepool"
title: SQLite for Tidepool
description: Why Tidepool's server keeps tide data in SQLite rather than Postgres.
confidence: high
days_ago: 9
sources: [dec-tp-sqlite, tp-postgres-cost, tp-tide-import-size, tp-backup-litestream, tp-read-only-api, tp-restore-drill]
related: ["project:tidepool", "concept:boring-tech-budget"]
---
# SQLite for Tidepool

Tidepool's server stores tide predictions in a single SQLite file. The data is
written once a day by an import job and read by everyone else, which is the
workload SQLite is best at.

## Why
- The full dataset is 380 MB for 3,100 stations and grows by about 2 MB a month.
- Writes happen in one nightly import; there is no concurrent writer to plan for.
- Backups are continuous with Litestream to object storage, restored in under a
  minute in the last drill.

## What would change it
User accounts with synced trip logs would add concurrent writes. That is the
point to revisit, not before.

## Timeline
- **74 days ago:** decided, after the Postgres instance cost more than the VPS.
- **9 days ago:** the restore drill passed.
