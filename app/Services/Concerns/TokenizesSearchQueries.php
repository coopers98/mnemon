<?php

namespace App\Services\Concerns;

/**
 * Shared tokenizer for the two full-text search paths.
 *
 * `WikiSearchService` and `PalaceSearchService` deliberately implement the same
 * matching contract and differ only in the match primitive, so they share one
 * tokenizer here rather than keeping two copies that can drift.
 *
 * The contract, in order:
 *
 *  1. Split on whitespace, lowercase, de-duplicate.
 *  2. Drop the words that carry no search signal: PostgreSQL's `english` stop
 *     words, and tokens with no letter or digit at all (`--`, `!&|:*`).
 *  3. If step 2 leaves nothing, the query is *all* filler — `will`, `IT`,
 *     `what do we` — and the words are matched **literally** as substrings
 *     instead (see `signalWords()`).
 *  4. Cap the surviving terms so a pasted document cannot reach the engine.
 *
 * Step 2 is what makes the score honest. PostgreSQL cannot match a stop word at
 * all — `plainto_tsquery('english','about')` is an empty tsquery and `@@`
 * against it is false for every row — so counting stop words in the denominator
 * scores a conversational prompt against words that can never be hit. See D11
 * in the release roadmap.
 */
trait TokenizesSearchQueries
{
    /**
     * Upper bound on the number of words that reach the SQL statement.
     *
     * Each word adds two SQL fragments (one to the OR membership test, one to
     * the CASE sum) and one `to_tsvector`/`LIKE` evaluation per scanned row, so
     * an uncapped query is both a correctness and a cost problem:
     *
     *   - SQLite raises "Expression tree is too large (maximum depth 1000)" at
     *     997 distinct words.
     *   - PostgreSQL raises SQLSTATE 54001 "stack depth limit exceeded" at
     *     ~4,063, and SQLSTATE HY000 "number of parameters must be between 0
     *     and 65535" beyond ~21,845.
     *   - Cost per scanned row is linear in the word count on both engines.
     *
     * `recall` passes the raw user prompt, so a pasted document reaches this.
     * 32 distinct signal words is well beyond any real search intent and far
     * below both engine limits.
     */
    protected const MAX_QUERY_WORDS = 32;

    /**
     * PostgreSQL 17's `english` snowball stop list, verbatim
     * (`$SHAREDIR/tsearch_data/english.stop`, 127 entries).
     *
     * Kept identical to PostgreSQL's own list on purpose: on PostgreSQL these
     * words are unmatchable, so dropping them here changes nothing about which
     * rows match and only removes them from the score denominator. On SQLite,
     * where `LIKE` has no notion of stop words, dropping them removes noise and
     * makes the two engines agree on the score.
     *
     * @var array<int, string>
     */
    protected const STOP_WORDS = [
        'i', 'me', 'my', 'myself', 'we', 'our', 'ours', 'ourselves', 'you', 'your',
        'yours', 'yourself', 'yourselves', 'he', 'him', 'his', 'himself', 'she',
        'her', 'hers', 'herself', 'it', 'its', 'itself', 'they', 'them', 'their',
        'theirs', 'themselves', 'what', 'which', 'who', 'whom', 'this', 'that',
        'these', 'those', 'am', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
        'have', 'has', 'had', 'having', 'do', 'does', 'did', 'doing', 'a', 'an',
        'the', 'and', 'but', 'if', 'or', 'because', 'as', 'until', 'while', 'of',
        'at', 'by', 'for', 'with', 'about', 'against', 'between', 'into',
        'through', 'during', 'before', 'after', 'above', 'below', 'to', 'from',
        'up', 'down', 'in', 'out', 'on', 'off', 'over', 'under', 'again',
        'further', 'then', 'once', 'here', 'there', 'when', 'where', 'why', 'how',
        'all', 'any', 'both', 'each', 'few', 'more', 'most', 'other', 'some',
        'such', 'no', 'nor', 'not', 'only', 'own', 'same', 'so', 'than', 'too',
        'very', 's', 't', 'can', 'will', 'just', 'don', 'should', 'now',
    ];

    /**
     * Split a query into distinct lowercase words. No filtering — this is the
     * raw token list, and the literal fallback in `searchTerms()` uses it.
     *
     * @return array<int, string>
     */
    protected function queryWords(string $query): array
    {
        return array_values(array_filter(
            array_unique(explode(' ', preg_replace('/\s+/', ' ', strtolower(trim($query))))),
            fn ($w) => strlen($w) > 0
        ));
    }

    /**
     * The words that can actually carry a hit: stop words and tokens with no
     * alphanumeric character removed.
     *
     * @param  array<int, string>  $words
     * @return array<int, string>
     */
    protected function signalWords(array $words): array
    {
        return array_values(array_filter(
            $words,
            fn ($w) => ! in_array($w, self::STOP_WORDS, true) && preg_match('/[\p{L}\p{N}]/u', $w) === 1
        ));
    }

    /**
     * Resolve a query to the terms the SQL will match on.
     *
     * Returns the signal words when there are any. When there are none the query
     * is nothing but stop words and/or punctuation — a single-word search for
     * `will`, `IT` or a project called `Down`, or the degenerate `what do we` —
     * and the raw words are returned with `literal = true`, meaning the caller
     * must match them as substrings on both engines rather than through the
     * dictionary that just discarded every one of them.
     *
     * @return array{terms: array<int, string>, literal: bool}
     */
    protected function searchTerms(string $query): array
    {
        $words = $this->queryWords($query);
        $signal = $this->signalWords($words);
        $literal = $signal === [];

        return [
            'terms' => $this->capTerms($literal ? $words : $signal),
            'literal' => $literal,
        ];
    }

    /**
     * Trim the term list to MAX_QUERY_WORDS, keeping the longest words.
     *
     * Length is a crude specificity proxy: in a pasted paragraph the long words
     * are the distinctive ones, so this keeps more signal than an arbitrary
     * prefix would. Ties break on first appearance, and the surviving terms are
     * returned in their original order so the same query always produces the
     * same SQL.
     *
     * @param  array<int, string>  $terms
     * @return array<int, string>
     */
    protected function capTerms(array $terms): array
    {
        if (count($terms) <= self::MAX_QUERY_WORDS) {
            return array_values($terms);
        }

        $ranked = [];
        foreach (array_values($terms) as $position => $term) {
            $ranked[] = [$term, $position];
        }

        usort($ranked, fn ($a, $b) => (mb_strlen($b[0]) <=> mb_strlen($a[0])) ?: ($a[1] <=> $b[1]));
        $ranked = array_slice($ranked, 0, self::MAX_QUERY_WORDS);
        usort($ranked, fn ($a, $b) => $a[1] <=> $b[1]);

        return array_column($ranked, 0);
    }

    /**
     * The LIKE escape character. Deliberately not a backslash: PDO scans the SQL
     * text itself to count `?` placeholders, and its scanner treats a backslash
     * inside a string literal as escaping the following character, so a literal
     * `ESCAPE '\'` makes PDO think the quote never closed and fail the whole
     * statement with "Invalid parameter number: parameter was not defined".
     */
    protected const LIKE_ESCAPE = '!';

    /**
     * Build the `%word%` pattern for a LIKE match, escaping the LIKE
     * metacharacters so a query of `%` cannot become a tautology that returns
     * every row, and `_` cannot match an arbitrary character. The escape
     * character escapes itself so a word containing it still matches literally.
     * Paired with `ESCAPE '!'` in the SQL fragments below.
     */
    protected function likePattern(string $word): string
    {
        $e = self::LIKE_ESCAPE;

        return '%'.str_replace([$e, '%', '_'], [$e.$e, $e.'%', $e.'_'], $word).'%';
    }

    /**
     * Build the membership and score SQL for a list of terms.
     *
     * Membership is OR across the terms — a row matches if ANY term matches —
     * and the raw score is the number of distinct terms present. `$column` is a
     * literal column reference supplied by the calling service; every term is a
     * binding, so nothing user-supplied reaches the SQL text.
     *
     * @param  array<int, string>  $terms
     * @return array{score: string, match: string, bindings: array<int, string>}
     */
    /**
     * @param  string|null  $tsvColumn  Stored tsvector column to match against.
     *                                  Passing null falls back to computing
     *                                  to_tsvector() inline, which is the D12
     *                                  defect: on a 24k-drawer corpus that
     *                                  re-tokenised every candidate row once
     *                                  per term and took 31s, exceeding PHP's
     *                                  execution limit. Callers on PostgreSQL
     *                                  should always pass the stored column.
     */
    protected function matchFragments(string $column, array $terms, bool $useTsvector, ?string $tsvColumn = null): array
    {
        $conditions = [];
        $bindings = [];

        foreach ($terms as $term) {
            if ($useTsvector) {
                $matchExpr = $tsvColumn ?? "to_tsvector('english', {$column})";
                $conditions[] = "{$matchExpr} @@ plainto_tsquery('english', ?)";
                $bindings[] = $term;
            } else {
                $conditions[] = "LOWER({$column}) LIKE ? ESCAPE '".self::LIKE_ESCAPE."'";
                $bindings[] = $this->likePattern($term);
            }
        }

        $score = '('.implode(' + ', array_map(
            fn ($c) => "CASE WHEN {$c} THEN 1 ELSE 0 END",
            $conditions
        )).')';

        return [
            'score' => $score,
            'match' => '('.implode(' OR ', $conditions).')',
            'bindings' => $bindings,
        ];
    }
}
