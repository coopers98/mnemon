---
name: "project:tidepool"
title: Tidepool
description: Offline-first tide-chart PWA for sea kayakers; in beta, heading for v1.0.
confidence: high
days_ago: 2
sources: [tp-origin, dec-tp-sqlite, tp-precache-14-days, tp-dst-bug, tp-v09-release, tp-sw-stale-tides, tp-beta-feedback, tp-currents-declined, tp-ios-install-prompt, tp-safari-storage-eviction, tp-restore-drill, tp-v1-plan]
related: ["concept:offline-first", "decision:sqlite-for-tidepool", "person:rowan"]
revisions:
  - days_ago: 48
    content: |
      # Tidepool

      Tidepool is a tide-chart web app for sea kayakers that works with no signal.
      Predictions are computed on the server and cached on the phone by a service
      worker. It is in a small beta.

      ## Key facts
      - Server data lives in one SQLite file, imported nightly.
      - Starred stations get 14 days of tides precached.
      - No user accounts; settings live on the device.

      ## Timeline
      - **74 days ago:** moved from managed Postgres to SQLite.
      - **52 days ago:** v0.8 to 25 testers.
      - **48 days ago:** fixed tide times an hour off after the clocks changed.
  - days_ago: 20
    content: |
      # Tidepool

      Tidepool is an offline-first tide-chart PWA for sea kayakers. Predictions are
      computed on the server from published harmonic constants and cached on the phone
      by a service worker, so charts open with no signal. It is in beta with 60 testers.

      ## Key facts
      - Server data: one SQLite file, 3,100 stations, imported nightly at 03:10.
      - Starred stations get 14 days of tides precached (up to 12 stations).
      - No user accounts in v1.
      - 23 of 60 testers open it weekly.

      ## Decisions
      - Tidal currents are out of v1: different dataset, different station IDs.

      ## Open items
      - The nightly import failed silently twice; it now has a timeout and a
        healthcheck ping.

      ## Timeline
      - **74 days ago:** moved from managed Postgres to SQLite.
      - **48 days ago:** fixed tide times an hour off after the clocks changed.
      - **45 days ago:** v0.9 to 60 testers.
      - **41 days ago:** fixed stale tide tables caused by a cache-first API route.
      - **27 days ago:** currents parked until after v1.
---
# Tidepool

Tidepool is an offline-first tide-chart PWA for sea kayakers. It started after
Rowan misread a printed tide table before a crossing; the goal is a chart that
opens in under a second with no signal and no account. Predictions are computed
on the server and cached on the phone by a service worker. It is in beta with 60
testers, and v1.0 is planned for the end of the month. See [[concept:offline-first|offline-first]].

## Key facts
- Server data is one SQLite file: 3,100 stations, imported nightly. See
  [[decision:sqlite-for-tidepool|SQLite for Tidepool]].
- The service worker precaches 14 days of tides for every starred station, because
  7 days ran out on a nine-day trip.
- Tide times are stored as UTC and formatted in each station's time zone. Local
  storage put times an hour off after the clocks changed.
- 23 of 60 beta testers open it weekly.

## Decisions
- No user accounts in v1.
- Tidal currents are out of v1. They come from a different dataset with its own
  station IDs. They were also the most requested feature (11 testers).
- The API route for tides is stale-while-revalidate with a 6-hour ceiling; only
  the app shell is cache-first.

## Open items
- iOS: there is no install prompt, and Safari clears cached tides after seven days
  unless the app is on the home screen. The install hint now says both; check
  whether iOS testers follow it.
- v1.0 remaining work: install hint copy, maskable icon, landing page.

## Timeline
- **95 days ago:** started.
- **74 days ago:** moved from managed Postgres to SQLite.
- **63 days ago:** precache window raised from 7 to 14 days.
- **48 days ago:** fixed the daylight-saving bug.
- **45 days ago:** v0.9 to 60 testers.
- **41 days ago:** fixed stale tide tables caused by a cache-first API route.
- **27 days ago:** currents parked until after v1.
- **12 days ago:** replaced the dead Install button on iOS with a Share-sheet hint.
- **9 days ago:** the restore drill passed in 48 seconds.
- **3 days ago:** v1.0 scope frozen.
