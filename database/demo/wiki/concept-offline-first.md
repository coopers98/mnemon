---
name: "concept:offline-first"
title: Offline-first
description: How Tidepool treats the network as optional, and what that has cost so far.
confidence: medium
days_ago: 14
sources: [rowan-cabin-internet, tp-server-side-predictions, tp-no-accounts-v1, tp-precache-14-days, tp-idb-quota, tp-sw-stale-tides, tp-sw-versioning, tp-offline-indicator]
related: ["project:tidepool"]
---
# Offline-first

Offline-first means the app works fully from what is already on the device, and
the network only refreshes it. For [[project:tidepool|Tidepool]] this is the product, not a
feature: kayakers check tides on beaches with no signal. Rowan tests it every day
on the cabin's satellite link, which drops in heavy rain.

## Key facts
- The server does the heavy work. Predictions are computed server-side and shipped
  as per-station JSON (~40 KB for 14 days), so the client stays small and needs no
  harmonic maths.
- Data the user needs is precached, not cached on first view. Starred stations
  get 14 days of tides up front.
- Every byte cached counts against a browser quota. Firefox for Android threw
  QuotaExceededError at 40 starred stations; the cap is now 12.
- No accounts. Starred stations and settings live in IndexedDB, so there is
  nothing to sync and no login to fail offline.

## Rules learned
- Cache-first is for the app shell only. Data routes use stale-while-revalidate
  with a maximum age; a cache-first tides route served week-old tables after v0.9.
- Never swap a running app's assets. A new service worker waits until the user
  taps "Update"; skipWaiting on install had changed assets under an open chart.
- Tell users how fresh the data is in words. "Tides synced 2 days ago" earned more
  trust than a green dot.

## Open items
- The 12-station cap is a guess, not a measurement. Each starred station adds
  about 40 KB to the precache.

## Timeline
- **88 days ago:** decided to compute predictions on the server.
- **63 days ago:** precache window raised to 14 days.
- **60 days ago:** storage quota hit; starred stations capped at 12.
- **57 days ago:** no accounts in v1.
- **41 days ago:** stale tides from a cache-first data route.
- **38 days ago:** service worker updates wait for the user.
- **34 days ago:** freshness shown as text.
