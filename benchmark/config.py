"""Benchmark configuration.

Defaults can be overridden via environment variables. The benchmark uses the
production Mnemon MCP API but namespaces all data under `benchmark:*` wings,
so it does not interfere with real palace content. Use cleanup.py after a run
to remove the benchmark wings.
"""

import os
from pathlib import Path

ROOT = Path(__file__).resolve().parent

MNEMON_URL = os.environ.get(
    "MNEMON_URL", "https://mnemon.example.com/api/mcp/call"
)

# API key: env var wins, then a file (default /tmp/.mnemon-api-key).
MNEMON_KEY_FILE = os.environ.get("MNEMON_KEY_FILE", "/tmp/.mnemon-api-key")


def mnemon_api_key() -> str:
    key = os.environ.get("MNEMON_API_KEY")
    if key:
        return key.strip()
    path = Path(MNEMON_KEY_FILE)
    if not path.exists():
        raise RuntimeError(
            f"No API key. Set MNEMON_API_KEY or write the key to {MNEMON_KEY_FILE}."
        )
    return path.read_text().strip()


# Wings get namespaced as benchmark:q{question_id}. Slugged to benchmark-q-{id}.
WING_PREFIX = "benchmark:q"
ROOM_NAME = "sessions"

# Dataset paths.
DATA_DIR = ROOT / "data"
DATASET_S = DATA_DIR / "longmemeval_s_cleaned.json"
DATASET_ORACLE = DATA_DIR / "longmemeval_oracle.json"

# Results / state.
RESULTS_DIR = ROOT / "results"
STATE_DIR = ROOT / "state"

# Ingestion tuning.
INGEST_WORKERS = int(os.environ.get("INGEST_WORKERS", "8"))
SEARCH_WORKERS = int(os.environ.get("SEARCH_WORKERS", "8"))

# OpenAI text-embedding-3-small caps at 8192 tokens. Truncate to ~30K chars
# (~7.5K tokens) to stay well under the limit.
MAX_DRAWER_CHARS = int(os.environ.get("MAX_DRAWER_CHARS", "30000"))

# Search params.
SEARCH_LIMIT = int(os.environ.get("SEARCH_LIMIT", "10"))  # capped server-side at 20
SEARCH_MODE = os.environ.get("SEARCH_MODE", "hybrid")  # semantic | fulltext | hybrid

# QA evaluation (Phase 4) — optional.
OPENAI_API_KEY = os.environ.get("OPENAI_API_KEY", "")
QA_MODEL = os.environ.get("QA_MODEL", "gpt-4o")

# SSH host for cleanup (uses tinker on the production server).
SSH_HOST = os.environ.get("MNEMON_SSH_HOST", "forge@198.51.100.10")
SSH_APP_PATH = os.environ.get(
    "MNEMON_SSH_APP_PATH", "/home/forge/mnemon.example.com/current"
)

# Slugger that mirrors Laravel's Str::slug behavior for our colon-style names.
# Mnemon does: Str::slug(str_replace(':', '-', $wingName))
def wing_name(question_id: str) -> str:
    return f"{WING_PREFIX}{question_id}"


def wing_slug(question_id: str) -> str:
    raw = wing_name(question_id).replace(":", "-")
    # Lowercase + collapse non-alphanumeric to dashes (matches Laravel's slug).
    out = []
    prev_dash = False
    for ch in raw.lower():
        if ch.isalnum():
            out.append(ch)
            prev_dash = False
        else:
            if not prev_dash:
                out.append("-")
                prev_dash = True
    return "".join(out).strip("-")
