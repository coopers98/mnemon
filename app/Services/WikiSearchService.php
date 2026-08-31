<?php

namespace App\Services;

use App\Services\Concerns\TokenizesSearchQueries;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WikiSearchService
{
    use TokenizesSearchQueries;

    /**
     * Search wiki pages by content with optional type filtering.
     *
     * One implementation for both engines; only the match primitive differs.
     * Membership is OR across the searchable terms — a page matches if ANY term
     * matches — and the raw score is the number of distinct terms present, over
     * the number of searchable terms. PostgreSQL uses
     * `to_tsvector @@ plainto_tsquery` per term, SQLite uses
     * `LOWER(content) LIKE '%term%' ESCAPE '!'`. Terms come from the shared
     * `TokenizesSearchQueries` tokenizer, so the two paths cannot drift, and
     * every term is a binding.
     *
     * The per-term form matters. Applying `plainto_tsquery` to the WHOLE query
     * ANDs every surviving lexeme ("what do we know about dorothy" becomes
     * 'know' & 'dorothi'), so a page about Dorothy that never says "know"
     * matched nothing. The conversational prompts `recall` feeds this service
     * are exactly that shape, so on the production driver it silently returned
     * no wiki context (D11). `websearch_to_tsquery` ANDs unquoted words too and
     * does not help.
     *
     * The engines are NOT ordered by capability; they trade in both directions.
     * PostgreSQL stems, so "engineers" matches "engineer" where LIKE cannot.
     * SQLite matches substrings, so `auth` matches "authentication", `compiler`
     * matches "WikiPageCompiler" and `1234` matches "ISSUE-1234" where
     * PostgreSQL's whole-lexeme `@@` cannot. Neither is a superset of the other.
     * Closing that gap on PostgreSQL (a trigram index, or matching substrings
     * alongside lexemes) is a design question of its own and is not done here;
     * the one case where PostgreSQL would otherwise return nothing at all — a
     * query of nothing but stop words — is handled by falling through to the
     * substring primitive (see `searchTerms()`).
     *
     * @param  string  $query  The search query
     * @param  string|null  $type  Optional page type filter (person, project, concept, decision, synthesis)
     * @param  int  $limit  Maximum number of results
     */
    public function search(
        string $query,
        ?string $type = null,
        int $limit = 5
    ): Collection {
        if (trim($query) === '') {
            return collect();
        }

        ['terms' => $terms, 'literal' => $literal] = $this->searchTerms($query);

        if ($terms === []) {
            return collect();
        }

        // PostgreSQL matches whole lexemes through the `english` dictionary;
        // SQLite matches substrings. A literal query (nothing but stop words
        // and punctuation) has no dictionary form on either engine, so both
        // fall through to the substring primitive — see searchTerms().
        $useTsvector = $this->isPostgres() && ! $literal;

        $fragments = $this->matchFragments('wiki_pages.content', $terms, $useTsvector, 'wiki_pages.content_tsv');

        $rows = $this->baseQuery($type)
            ->selectRaw("
                wiki_pages.id,
                wiki_pages.name,
                wiki_pages.type,
                wiki_pages.title,
                wiki_pages.content,
                wiki_pages.description,
                wiki_pages.last_compiled_at,
                {$fragments['score']} AS raw_score
            ", $fragments['bindings'])
            ->whereRaw($fragments['match'], $fragments['bindings'])
            ->orderByRaw("{$fragments['score']} DESC", $fragments['bindings'])
            ->limit($limit)
            ->get();

        return $this->normalizeAndWrap($rows, count($terms));
    }

    protected function baseQuery(?string $type): Builder
    {
        $query = DB::table('wiki_pages');

        if ($type !== null) {
            $query->where('type', $type);
        }

        return $query;
    }

    /**
     * Turn the raw word-hit count into a 0-1 score.
     *
     * The denominator is the number of *searchable* terms (`searchTerms()`), not
     * the number of words the user typed. That is what makes the score both
     * honest and usable: `recall` filters this service's output against
     * `mnemon.recall.confidence_floor` (0.45 as shipped), and "what do we know
     * about dorothy" has six words but only two searchable ones, so a page that
     * says "dorothy" scores 0.5 and clears the floor instead of scoring 0.167
     * and being discarded. A query where every term hits still scores exactly
     * 1.0, unchanged. See D11 in the release roadmap.
     */
    protected function normalizeAndWrap(Collection $rows, int $termCount): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }

        $max = max(1, $termCount);

        return $rows->map(function ($row) use ($max) {
            $normalized = min(1.0, (float) $row->raw_score / $max);

            return (object) [
                'id' => $row->id,
                'name' => $row->name,
                'type' => $row->type,
                'title' => $row->title,
                'content' => $row->content,
                'description' => $row->description,
                'last_compiled_at' => $row->last_compiled_at,
                'score' => round($normalized, 6),
            ];
        });
    }

    protected function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
}
