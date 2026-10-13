---
name: "project:quarry"
title: Quarry
description: A Rust CLI that searches shell history by text, directory, date and exit code.
confidence: high
days_ago: 6
sources: [qy-origin, dec-quarry-rust, qy-release-0-1, qy-index, qy-hook-latency, qy-release-0-4, qy-secrets-filter, qy-stars, qy-windows-declined, qy-release-0-5, qy-musl-build]
related: ["person:rowan", "concept:boring-tech-budget"]
---
# Quarry

Quarry is a command-line tool that searches shell history by text, working
directory, date and exit code. It exists because Ctrl-R cannot answer "what was
that rsync I ran in this directory last month?". It is written in Rust, the one
new technology this project was allowed (see [[concept:boring-tech-budget|boring technology budget]]),
and ships as a single static binary.

## Key facts
- Supports zsh, bash and fish history. The latest release is 0.5.0; the binary is
  2.1 MB stripped.
- The index is SQLite with an FTS5 table under the XDG data directory. Indexing
  48,000 lines takes 0.6 s, and queries return in under 10 ms.
- A precmd hook records the working directory and exit code. It appends to a log
  that Quarry indexes on the next search, adding 0.4 ms per prompt instead of 11 ms.
- Commands that export variables ending in TOKEN, KEY, SECRET or PASSWORD are never
  indexed.
- About 140 GitHub stars, most of them from one forum post.

## Decisions
- No Windows support: PowerShell history has no timestamps or directories.
- Release builds are static musl binaries after glibc errors on older distributions.

## Open items
- Watch whether the musl builds close out the install issues from the forum post.

## Timeline
- **94 days ago:** started.
- **58 days ago:** moved the hook to a log file, cutting prompt latency from 11 ms
  to 0.4 ms.
- **33 days ago:** 0.4.0 with fish support.
- **26 days ago:** secret filter added at index time.
- **21 days ago:** forum post; 12 to 140 stars in a day.
- **17 days ago:** declined Windows support.
- **11 days ago:** 0.5.0 with quarry stats.
- **6 days ago:** static musl release builds.
