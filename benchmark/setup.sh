#!/usr/bin/env bash
# Download the LongMemEval dataset.
set -euo pipefail

cd "$(dirname "$0")"
mkdir -p data results state

cd data

S_FILE="longmemeval_s_cleaned.json"
ORACLE_FILE="longmemeval_oracle.json"
BASE="https://huggingface.co/datasets/xiaowu0162/longmemeval-cleaned/resolve/main"

if [[ -f "$S_FILE" && -s "$S_FILE" ]]; then
  echo "[setup] $S_FILE already present ($(du -h "$S_FILE" | cut -f1))"
else
  echo "[setup] downloading $S_FILE ..."
  wget --quiet --show-progress "$BASE/$S_FILE"
fi

if [[ -f "$ORACLE_FILE" && -s "$ORACLE_FILE" ]]; then
  echo "[setup] $ORACLE_FILE already present ($(du -h "$ORACLE_FILE" | cut -f1))"
else
  echo "[setup] downloading $ORACLE_FILE ..."
  wget --quiet --show-progress "$BASE/$ORACLE_FILE" || \
    echo "[setup] WARN: oracle dataset not available (it is optional)"
fi

echo "[setup] done."
