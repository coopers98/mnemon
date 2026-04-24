<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WikiSearchService
{
    /**
     * Search wiki pages by content with optional type filtering.
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

        if ($this->isPostgres()) {
            return $this->postgresSearch($query, $type, $limit);
        }

        return $this->sqliteSearch($query, $type, $limit);
    }

    protected function postgresSearch(string $query, ?string $type, int $limit): Collection
    {
        $base = $this->baseQuery($type);

        $rows = $base
            ->selectRaw("
                wiki_pages.id,
                wiki_pages.name,
                wiki_pages.type,
                wiki_pages.title,
                wiki_pages.content,
                wiki_pages.description,
                wiki_pages.last_compiled_at,
                ts_rank(to_tsvector('english', wiki_pages.content), plainto_tsquery('english', ?)) AS raw_score
            ", [$query])
            ->whereRaw("to_tsvector('english', wiki_pages.content) @@ plainto_tsquery('english', ?)", [$query])
            ->orderByRaw('raw_score DESC')
            ->limit($limit)
            ->get();

        return $this->normalizeAndWrap($rows);
    }

    protected function sqliteSearch(string $query, ?string $type, int $limit): Collection
    {
        $words = array_values(array_filter(
            array_unique(explode(' ', preg_replace('/\s+/', ' ', strtolower(trim($query))))),
            fn ($w) => strlen($w) > 0
        ));

        if (empty($words)) {
            return collect();
        }

        $base = $this->baseQuery($type);

        $conditions = [];
        $selectBindings = [];
        $whereConditions = [];
        $whereBindings = [];
        foreach ($words as $word) {
            $conditions[] = 'CASE WHEN LOWER(wiki_pages.content) LIKE ? THEN 1 ELSE 0 END';
            $selectBindings[] = '%'.$word.'%';
            $whereConditions[] = 'LOWER(wiki_pages.content) LIKE ?';
            $whereBindings[] = '%'.$word.'%';
        }

        $scoreSql = '('.implode(' + ', $conditions).')';
        $matchSql = '('.implode(' OR ', $whereConditions).')';

        $rows = $base
            ->selectRaw("
                wiki_pages.id,
                wiki_pages.name,
                wiki_pages.type,
                wiki_pages.title,
                wiki_pages.content,
                wiki_pages.description,
                wiki_pages.last_compiled_at,
                {$scoreSql} AS raw_score
            ", $selectBindings)
            ->whereRaw($matchSql, $whereBindings)
            ->orderByRaw("{$scoreSql} DESC", $selectBindings)
            ->limit($limit)
            ->get();

        return $this->normalizeAndWrap($rows, count($words));
    }

    protected function baseQuery(?string $type): Builder
    {
        $query = DB::table('wiki_pages');

        if ($type !== null) {
            $query->where('type', $type);
        }

        return $query;
    }

    protected function normalizeAndWrap(Collection $rows, ?float $maxPossible = null): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }

        $max = $maxPossible ?? $rows->max('raw_score');
        if ($max <= 0) {
            $max = 1.0;
        }

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
