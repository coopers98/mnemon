#!/usr/bin/env bash
# Mnemon backfill: import this device's past Claude Code sessions and memory
# notes into Mnemon.
#
# Usage: mnemon-backfill.sh [--dry-run] [options]     (--help lists them)
#
# With no arguments, or --dry-run, it is the free scan: it reads
# ~/.claude/projects, asks the server one brain_status (which costs nothing)
# and prints what an import would send, what it would cost, and what it skips
# and why. It sends nothing to the model and writes nothing under ~/.mnemon.
#
# --project <name> (the trial) or --all imports: the same scan and estimate,
# a confirmation (--yes skips it, never the cap), the memory notes, then the
# transcripts one model call at a time under the cap. It can be stopped with
# Ctrl-C and resumed with the same command (lib/backfill-run.sh).
#
# Runs wherever the hooks run -- Linux, macOS, Git Bash on Windows -- so plain
# bash, curl, jq and coreutils only. Output is plain text with no colour, and
# any prompt text it shows is redacted and cut to 60 characters.
set -u
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/backfill-scan.sh
. "$SCRIPT_DIR/lib/backfill-scan.sh"
# shellcheck source=lib/backfill-run.sh
. "$SCRIPT_DIR/lib/backfill-run.sh"

backfill_parse_args "$@"
rc=$?
if [ "$rc" -ne 0 ]; then
    backfill_usage >&2
    exit 2
fi
if [ "$BACKFILL_HELP" = 1 ]; then
    backfill_usage
    exit 0
fi

if [ "$BACKFILL_DRY_RUN" = 0 ] && { [ -n "$BACKFILL_PROJECT" ] || [ "$BACKFILL_ALL" = 1 ]; }; then
    backfill_run
    exit $?
fi

echo "Mnemon backfill: scan (nothing is sent to the model)"
echo "Projects: $BACKFILL_PROJECTS_ROOT"
if [ ! -d "$BACKFILL_PROJECTS_ROOT" ]; then
    echo "No Claude Code projects found there; nothing to import."
    exit 0
fi
echo

backfill_scan "$BACKFILL_PROJECTS_ROOT" > "$BACKFILL_TMP/scan.tsv"
backfill_notes "$BACKFILL_PROJECTS_ROOT" > "$BACKFILL_TMP/notes.tsv"
backfill_load_status
backfill_print_scan "$BACKFILL_TMP/scan.tsv" "$BACKFILL_TMP/notes.tsv"
exit 0
