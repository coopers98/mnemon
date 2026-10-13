---
name: "lessons-from-three-launches"
title: Lessons from three launches
description: What Tidepool's beta, Ledgerline's growth and Quarry's forum post taught about launching.
confidence: medium
days_ago: 3
sources: [ll-customers-count, ll-customers-41, ll-onboarding-redesign, ll-priya-editor-mockups, tp-v08-release, tp-v09-release, tp-beta-feedback, tp-ios-install-prompt, qy-release-0-1, qy-stars, qy-musl-build, rowan-launch-reflection]
related: ["project:tidepool", "project:ledgerline", "project:quarry"]
revisions:
  - days_ago: 19
    content: |
      # Lessons from three launches

      Three products, three ways of launching. Quarry's single forum post did more
      than Tidepool's two months of careful beta.

      ## Observations
      - Quarry 0.1.0 shipped unannounced and got three outside downloads. One forum
        post took it from 12 to 140 stars in a day.
      - Tidepool's beta grew from 25 to 60 testers through kayak club mailing lists,
        but only 23 open it weekly.
      - Ledgerline has 41 paying customers, nearly all traced back to one forum post
        two years ago.

      ## Open items
      - Post Tidepool earlier than planned.
---
# Lessons from three launches

Rowan has launched three products in three different ways. Ledgerline grew from
one forum post years ago. Tidepool went through a careful two-month beta. Quarry
went out unannounced and was then posted once. The pattern so far: a short post
with a one-line install reaches more people than polish does, but only if the
first install works.

## What happened
- **Ledgerline:** 41 paying customers, nearly all traced back to one post in a
  freelancers' forum two years ago. It had 34 three months ago; the
  onboarding redesign since moved trial-to-paid from 5 of 30 to 9 of 31.
- **Tidepool:** v0.8 went to 25 testers, v0.9 to 60 through two kayak club mailing
  lists. 23 open it weekly.
- **Quarry:** 0.1.0 shipped unannounced and got three outside downloads. One post
  in a terminal-tools forum took it from 12 to 140 stars in a day.

## Lessons
- **Post early, with a one-line install and a GIF.** Quarry's post did more in a
  day than Tidepool's beta did in two months.
- **The first install is the launch.** Four of Quarry's six post-launch issues
  were install failures on older Linux; static musl builds replaced the glibc
  ones 15 days later. On iOS, Tidepool's Install button did nothing for half the
  beta testers.
- **Onboarding converts the people who already came.** Cutting Ledgerline's
  onboarding from five steps to two took trial-to-paid from 5 of 30 to 9 of 31.

## Open items
- Post Tidepool before v1.0 rather than after, once the iOS install hint is in.

## Timeline
- **95 days ago:** Ledgerline at 34 customers.
- **76 days ago:** Quarry 0.1.0, unannounced.
- **52 days ago:** Tidepool v0.8 to 25 testers.
- **45 days ago:** Tidepool v0.9 to 60 testers.
- **39 days ago:** Ledgerline's new onboarding live.
- **25 days ago:** Ledgerline at 41 customers.
- **21 days ago:** Quarry's forum post.
- **12 days ago:** iOS install hint replaced the dead button.
- **6 days ago:** Quarry switched to static musl builds.
