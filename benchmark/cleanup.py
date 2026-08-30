"""Remove the benchmark wings.

No MCP tool deletes anything, so this goes through artisan in the container.
The prefix guard is deliberate: an empty or wildcard prefix would match every
wing, and this script is one typo away from deleting a real palace.
"""

from __future__ import annotations

import argparse
import shutil
import subprocess
import sys

import config

DEFAULT_PREFIX = "benchmark-q"


def build_tinker_expression(prefix: str) -> str:
    if not prefix or prefix.strip() in ("", "*"):
        raise ValueError(
            f"refusing to delete with prefix {prefix!r} — that would match every wing"
        )
    if "%" in prefix or "_" in prefix:
        # Both are SQL LIKE metacharacters, and this script builds
        # `LIKE '{prefix}%'` straight from --prefix with no escaping of the
        # prefix itself (only of \ and ' below, for the tinker string
        # literal — that does not neutralize LIKE semantics). '_' matches
        # any single character, so "_" alone becomes `LIKE '_%'`, which
        # matches every non-empty wing slug — a one-character typo away from
        # the "delete a real palace" case this guard exists to prevent. '%'
        # matches any run of characters, so "%q" becomes `LIKE '%q%'`.
        raise ValueError(
            f"refusing to delete with prefix {prefix!r} — '%' and '_' are SQL "
            "LIKE metacharacters and this script does not escape them; a "
            "prefix containing either could match far more than intended"
        )
    safe = prefix.replace("\\", "\\\\").replace("'", "\\'")
    return (
        f"$w = App\\Models\\Wing::where('slug', 'like', '{safe}%')->get(); "
        "echo $w->count().' wings'; "
        "$w->each(fn($x) => $x->delete());"
    )


def clear_state_for_prefix(prefix: str) -> int:
    """Delete only the ingestion state whose wings this run actually deleted.

    Wiping every state file regardless of --prefix is not a tidiness bug, it is
    a data hazard: ingest.py has no server-side dedup key, so a question whose
    drawers still exist but whose state was discarded gets fully re-sent on the
    next run, silently doubling its haystack. Cleaning up a narrow test prefix
    must not arm that for the real corpus.
    """
    state_dir = config.STATE_DIR / "ingested"
    if not state_dir.exists():
        return 0
    cleared = 0
    for path in state_dir.glob("*.json"):
        if config.wing_slug(path.stem).startswith(prefix):
            path.unlink()
            cleared += 1
    return cleared


def main() -> int:
    parser = argparse.ArgumentParser(description="Delete benchmark wings.")
    parser.add_argument("--prefix", default=DEFAULT_PREFIX)
    parser.add_argument("--project", default="mnemon-bench")
    parser.add_argument("--env-file", default=".env.bench")
    parser.add_argument("--yes", action="store_true", help="skip the confirmation")
    args = parser.parse_args()

    expression = build_tinker_expression(args.prefix)

    if not args.yes:
        print(f"About to delete every wing whose slug starts with {args.prefix!r} "
              f"in compose project {args.project!r}.")
        if input("Type 'delete' to confirm: ").strip() != "delete":
            print("aborted")
            return 1

    if not shutil.which("docker"):
        print("[cleanup] docker not found", file=sys.stderr)
        return 1

    result = subprocess.run(
        ["docker", "compose", "-p", args.project, "--env-file", args.env_file,
         "exec", "-T", "app", "php", "artisan", "tinker", "--execute", expression],
        capture_output=True,
        text=True,
        # compose.yaml and .env.bench live at the repo root, not in
        # benchmark/. Without pinning cwd, `docker compose` resolves
        # --env-file relative to wherever this script happens to be
        # invoked from, so `cd benchmark && python3 cleanup.py` (the
        # invocation used everywhere else in this harness) fails to find
        # the env file even though the container is running fine.
        cwd=config.ROOT.parent,
    )
    print(result.stdout.strip())
    if result.returncode != 0:
        print(result.stderr.strip(), file=sys.stderr)
        return result.returncode

    cleared = clear_state_for_prefix(args.prefix)
    if cleared:
        print(f"[cleanup] cleared ingestion state for {cleared} question(s)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
