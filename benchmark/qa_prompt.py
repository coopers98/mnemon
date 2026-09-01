"""Reader and judge prompts.

Kept pure and separate from the HTTP layer so the wording — which materially
moves the score — can be tested and reviewed without spending anything, and so
the exact prompts can be recorded alongside the results.
"""

from __future__ import annotations

from dataset import session_text

READER_SYSTEM = (
    "You answer questions using only the conversation excerpts provided. "
    "The excerpts are prior conversations between the user and an assistant, "
    "given in relevance order and labelled with the date each took place. "
    "Answer only from those excerpts. Do not use outside knowledge and do not "
    "guess. If the excerpts do not contain the answer, reply with exactly "
    "NOT FOUND."
)

JUDGE_SYSTEM = (
    "You grade a candidate answer against a reference answer. Reply with "
    "exactly one word: CORRECT if the candidate conveys the same fact as the "
    "reference, or INCORRECT if it does not. Differences in wording, "
    "formatting, extra detail, or verbosity do not matter — only whether the "
    "substantive fact matches. A candidate of NOT FOUND is INCORRECT unless "
    "the reference itself says the information is unavailable."
)


def sessions_for_row(row: dict, record: dict, k: int) -> list[tuple[str, str]]:
    """The top-k retrieved sessions as (session_id, rendered text), in rank order.

    Rank order is preserved deliberately: the reader is told the excerpts are
    ordered by relevance, and reordering them would change what is being
    measured.

    A handful of LongMemEval records repeat the same session id twice in
    `haystack_session_ids` (a distractor session padded in at a second date).
    ingest.py walks those lists by index and skips `drawer_add` for a source
    id it has already sent, so only the *first* occurrence of a repeated id
    is ever actually ingested and retrievable. The id->session/date lookup
    below is built the same way — first occurrence wins — so a repeated id
    resolves to the same text and date the server actually holds, not
    whichever duplicate happens to come last in the record.
    """
    ids = record.get("haystack_session_ids", [])
    sessions = record.get("haystack_sessions", [])
    dates = record.get("haystack_dates", [])

    by_id: dict[str, list] = {}
    date_by_id: dict[str, str] = {}
    for index, sid in enumerate(ids):
        if sid in by_id:
            continue
        by_id[sid] = sessions[index] if index < len(sessions) else []
        date_by_id[sid] = dates[index] if index < len(dates) else None

    out: list[tuple[str, str]] = []
    for sid in row.get("retrieved", [])[:k]:
        session = by_id.get(sid)
        if session is None:
            continue
        date = date_by_id.get(sid)
        header = f"[{date}]" if date else "[date unknown]"
        out.append((sid, f"{header}\n{session_text(session)}"))
    return out


def build_reader_prompt(
    question: str, sessions: list[tuple[str, str]], question_date: str | None
) -> list[dict]:
    if sessions:
        body = "\n\n---\n\n".join(text for _, text in sessions)
        excerpts = f"Conversation excerpts, most relevant first:\n\n{body}"
    else:
        excerpts = "No conversation excerpts were retrieved."

    asked = f"\n\nThe question is being asked on {question_date}." if question_date else ""

    return [
        {"role": "system", "content": READER_SYSTEM},
        {"role": "user", "content": f"{excerpts}{asked}\n\nQuestion: {question}"},
    ]


def build_judge_prompt(question: str, gold: str, answer: str) -> list[dict]:
    return [
        {"role": "system", "content": JUDGE_SYSTEM},
        {
            "role": "user",
            "content": (
                f"Question: {question}\n\n"
                f"Reference answer: {gold}\n\n"
                f"Candidate answer: {answer}\n\n"
                "Reply with exactly CORRECT or INCORRECT."
            ),
        },
    ]
