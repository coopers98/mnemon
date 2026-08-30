<?php

namespace App\Services;

use App\Services\Concerns\TokenizesSearchQueries;
use App\Services\Embeddings\NullDriver;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PalaceSearchService
{
    use TokenizesSearchQueries;

    public function __construct(
        protected EmbeddingManager $embeddingManager,
    ) {}

    /**
     * Search drawers using the specified mode.
     *
     * @param  string  $query  The search query
     * @param  string|null  $wing  Wing slug to scope the search to
     * @param  string|null  $room  Room slug to scope the search to
     * @param  int  $limit  Maximum number of results
     * @param  string  $mode  Search mode: 'hybrid', 'fulltext', or 'semantic'
     * @param  string|null  $tier  Drawer tier filter: 'raw', 'reviewed', or 'consolidated'
     */
    public function search(
        string $query,
        ?string $wing = null,
        ?string $room = null,
        int $limit = 5,
        string $mode = 'hybrid',
        ?string $tier = null,
    ): Collection {
        if (trim($query) === '') {
            return collect();
        }

        return match ($mode) {
            'semantic' => $this->semanticSearch($query, $wing, $room, $limit, $tier),
            'fulltext' => $this->fulltextSearch($query, $wing, $room, $limit, $tier),
            default => $this->hybridSearch($query, $wing, $room, $limit, $tier),
        };
    }

    /**
     * Semantic search using pgvector cosine distance.
     * Returns empty collection on SQLite or when NullDriver is active.
     */
    protected function semanticSearch(
        string $query,
        ?string $wing,
        ?string $room,
        int $limit,
        ?string $tier = null,
    ): Collection {
        if (! $this->isPostgres()) {
            Log::warning('PalaceSearchService: semantic search requires PostgreSQL with pgvector; returning empty results.');

            return collect();
        }

        $driver = $this->embeddingManager->driver();
        if ($driver instanceof NullDriver) {
            Log::warning('PalaceSearchService: semantic search requires an embedding driver; NullDriver is active — returning empty results.');

            return collect();
        }

        $vector = $driver->embed($query);
        if ($vector === null) {
            Log::warning('PalaceSearchService: embedding returned null — returning empty results.');

            return collect();
        }

        $vectorLiteral = '['.implode(',', $vector).']';

        $base = $this->baseQuery($wing, $room, $tier);
        $rows = $base
            ->selectRaw('
                drawers.id,
                drawers.content,
                drawers.source,
                drawers.metadata,
                drawers.tier,
                drawers.retention_score,
                drawers.created_at,
                wings.name AS wing,
                wings.slug AS wing_slug,
                rooms.name AS room,
                rooms.slug AS room_slug,
                (1 - (drawers.embedding <=> ?::vector)) AS raw_score
            ', [$vectorLiteral])
            ->orderByRaw('drawers.embedding <=> ?::vector', [$vectorLiteral])
            // Cosine distances tie far less often than term counts, but the same
            // reasoning applies — see D19 in the fulltext branch below.
            ->orderBy('drawers.id')
            ->limit($limit)
            ->get();

        return $this->normalizeAndWrap($rows, 'raw_score');
    }

    /**
     * Full-text search.
     *
     * One implementation for both engines; only the match primitive differs.
     * Membership is OR across the searchable terms — a drawer matches if ANY
     * term matches — and the raw score is the number of distinct terms present,
     * over the number of searchable terms. PostgreSQL uses
     * `to_tsvector @@ plainto_tsquery` per term, SQLite uses
     * `LOWER(content) LIKE '%term%' ESCAPE '!'`. Terms come from the shared
     * `TokenizesSearchQueries` tokenizer, so the two paths cannot drift, and
     * every term is a binding.
     *
     * The per-term form matters. Applying `plainto_tsquery` to the WHOLE query
     * ANDs every surviving lexeme ("what do we know about dorothy" becomes
     * 'know' & 'dorothi'), so a drawer about Dorothy that never says "know"
     * matched nothing — the D11 defect, and it reaches `recall` through
     * hybridSearch(). `websearch_to_tsquery` ANDs unquoted words too and does
     * not help.
     *
     * The engines are NOT ordered by capability; they trade in both directions.
     * PostgreSQL stems, so "engineers" matches "engineer" where LIKE cannot.
     * SQLite matches substrings, so `auth` matches "authentication" and `1234`
     * matches "ISSUE-1234" where PostgreSQL's whole-lexeme `@@` cannot. Neither
     * is a superset of the other; the one case where PostgreSQL would otherwise
     * return nothing at all — a query of nothing but stop words — is handled by
     * falling through to the substring primitive (see `searchTerms()`).
     */
    protected function fulltextSearch(
        string $query,
        ?string $wing,
        ?string $room,
        int $limit,
        ?string $tier = null,
    ): Collection {
        ['terms' => $terms, 'literal' => $literal] = $this->searchTerms($query);

        if ($terms === []) {
            return collect();
        }

        $fragments = $this->matchFragments(
            'drawers.content',
            $terms,
            $this->isPostgres() && ! $literal,
        );

        $rows = $this->baseQuery($wing, $room, $tier)
            ->selectRaw("
                drawers.id,
                drawers.content,
                drawers.source,
                drawers.metadata,
                drawers.tier,
                drawers.retention_score,
                drawers.created_at,
                wings.name AS wing,
                wings.slug AS wing_slug,
                rooms.name AS room,
                rooms.slug AS room_slug,
                {$fragments['score']} AS raw_score
            ", $fragments['bindings'])
            ->whereRaw($fragments['match'], $fragments['bindings'])
            ->orderByRaw("{$fragments['score']} DESC", $fragments['bindings'])
            // D19: the score is a coarse count of matched terms, so ties are the
            // normal case. Without a secondary key PostgreSQL may return tied
            // rows in any order — its sort is not stable — so the same query can
            // put a different drawer first and a LIMIT can take a different
            // subset of equally-scored rows.
            ->orderBy('drawers.id')
            ->limit($limit)
            ->get();

        return $this->normalizeAndWrap($rows, 'raw_score', count($terms));
    }

    /**
     * Hybrid search: semantic + fulltext on Postgres; fulltext + temporal on SQLite.
     */
    protected function hybridSearch(
        string $query,
        ?string $wing,
        ?string $room,
        int $limit,
        ?string $tier = null,
    ): Collection {
        $weights = config('mnemon.retrieval.weights');
        $temporalWeight = (float) ($weights['temporal'] ?? 0.1);
        $boostDays = (int) config('mnemon.retrieval.temporal_boost_days', 7);

        if ($this->isPostgres()) {
            $driver = $this->embeddingManager->driver();
            $semanticWeight = (float) ($weights['semantic'] ?? 0.6);
            $fulltextWeight = (float) ($weights['fulltext'] ?? 0.3);

            $fulltextResults = $this->fulltextSearch($query, $wing, $room, $limit * 3, $tier);
            $ftById = $fulltextResults->keyBy('id');

            if (! ($driver instanceof NullDriver)) {
                $semanticResults = $this->semanticSearch($query, $wing, $room, $limit * 3, $tier);
                $semById = $semanticResults->keyBy('id');
            } else {
                $semById = collect();
            }

            $allIds = $ftById->keys()->merge($semById->keys())->unique();

            $combined = $allIds->map(function ($id) use ($ftById, $semById, $semanticWeight, $fulltextWeight, $temporalWeight, $boostDays) {
                $ft = $ftById->get($id);
                $sem = $semById->get($id);
                $base = $ft ?? $sem;

                $ftScore = $ft ? (float) $ft->score : 0.0;
                $semScore = $sem ? (float) $sem->score : 0.0;

                $temporal = $this->temporalBoost($base->created_at, $boostDays);

                $combinedScore = ($semScore * $semanticWeight)
                    + ($ftScore * $fulltextWeight)
                    + ($temporal * $temporalWeight);

                $retention = isset($base->retention_score) ? max(0.0, min(1.0, (float) $base->retention_score)) : 1.0;
                $finalScore = $this->applyRetentionBoost($combinedScore, $retention);

                return (object) [
                    'id' => $base->id,
                    'content' => $base->content,
                    'wing' => $base->wing,
                    'wing_slug' => $base->wing_slug,
                    'room' => $base->room,
                    'room_slug' => $base->room_slug,
                    'source' => $base->source,
                    'metadata' => $this->decodeMetadata($base->metadata),
                    'tier' => $base->tier ?? 'raw',
                    'retention_score' => $retention,
                    'created_at' => $base->created_at,
                    'score' => round($finalScore, 6),
                ];
            });
        } else {
            // SQLite: fulltext + temporal only
            $fulltextWeight = (float) ($weights['fulltext'] ?? 0.3);

            $fulltextResults = $this->fulltextSearch($query, $wing, $room, $limit * 3, $tier);

            $combined = $fulltextResults->map(function ($row) use ($fulltextWeight, $temporalWeight, $boostDays) {
                $ftScore = (float) $row->score;
                $temporal = $this->temporalBoost($row->created_at, $boostDays);

                $combinedScore = ($ftScore * $fulltextWeight) + ($temporal * $temporalWeight);

                $retention = isset($row->retention_score) ? max(0.0, min(1.0, (float) $row->retention_score)) : 1.0;
                $finalScore = $this->applyRetentionBoost($combinedScore, $retention);

                return (object) [
                    'id' => $row->id,
                    'content' => $row->content,
                    'wing' => $row->wing,
                    'wing_slug' => $row->wing_slug,
                    'room' => $row->room,
                    'room_slug' => $row->room_slug,
                    'source' => $row->source,
                    'metadata' => $this->decodeMetadata($row->metadata),
                    'tier' => $row->tier ?? 'raw',
                    'retention_score' => $retention,
                    'created_at' => $row->created_at,
                    'score' => round($finalScore, 6),
                ];
            });
        }

        return $combined
            ->sortByDesc('score')
            ->values()
            ->take($limit);
    }

    /**
     * Build the base query with joins and optional wing/room/tier scoping.
     */
    protected function baseQuery(?string $wing, ?string $room, ?string $tier = null): Builder
    {
        $query = DB::table('drawers')
            ->join('rooms', 'drawers.room_id', '=', 'rooms.id')
            ->join('wings', 'rooms.wing_id', '=', 'wings.id')
            ->whereNull('drawers.deleted_at');

        if ($wing !== null) {
            $query->where('wings.slug', $wing);
        }

        if ($room !== null) {
            $query->where('rooms.slug', $room);
        }

        if ($tier !== null) {
            $query->where('drawers.tier', $tier);
        }

        return $query;
    }

    /**
     * Normalize raw scores to 0-1 and wrap in objects with a `score` key.
     *
     * `$maxPossible` is the full-text denominator: the number of *searchable*
     * terms, not the number of words the user typed. Stop words cannot be hit
     * on PostgreSQL, so counting them would score a conversational prompt
     * against words that can never match — see `TokenizesSearchQueries`. Passed
     * null (semantic search) the scores are normalized against the best row
     * instead, since cosine similarity has no natural maximum.
     */
    /**
     * D18 — these results come from `DB::table()`, which bypasses the `Drawer`
     * model and its `array` cast, so `metadata` arrives as the raw JSON column.
     * `drawer_get` goes through the model and returns an object; without this,
     * the same field has two types depending on which tool the client called.
     */
    protected function decodeMetadata(mixed $metadata): ?array
    {
        if ($metadata === null || $metadata === '') {
            return null;
        }

        if (is_array($metadata)) {
            return $metadata;
        }

        $decoded = json_decode((string) $metadata, true);

        return is_array($decoded) ? $decoded : null;
    }

    protected function normalizeAndWrap(Collection $rows, string $scoreKey, ?float $maxPossible = null): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }

        $max = $maxPossible ?? $rows->max($scoreKey);
        if ($max <= 0) {
            $max = 1.0;
        }

        return $rows->map(function ($row) use ($scoreKey, $max) {
            $normalized = min(1.0, (float) $row->{$scoreKey} / $max);

            return (object) [
                'id' => $row->id,
                'content' => $row->content,
                'wing' => $row->wing,
                'wing_slug' => $row->wing_slug,
                'room' => $row->room,
                'room_slug' => $row->room_slug,
                'source' => $row->source,
                'metadata' => $this->decodeMetadata($row->metadata),
                'tier' => $row->tier ?? 'raw',
                'retention_score' => isset($row->retention_score) ? (float) $row->retention_score : 1.0,
                'created_at' => $row->created_at,
                'score' => round($normalized, 6),
            ];
        });
    }

    /**
     * Compute temporal boost score (0-1) based on content age.
     */
    protected function temporalBoost(string|\DateTimeInterface $createdAt, int $boostDays): float
    {
        $created = is_string($createdAt) ? new \DateTimeImmutable($createdAt) : $createdAt;
        $daysOld = (float) (new \DateTimeImmutable)->diff($created)->days;

        if ($daysOld >= $boostDays) {
            return 0.0;
        }

        return 1.0 - ($daysOld / $boostDays);
    }

    protected function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * Item 14: Retention boost — multiply combined relevance by a retention factor.
     *
     * retention_score is on [0, 1]. We linearly blend with 1 so that fully decayed items
     * are deprioritized but not zeroed out (50% floor by default).
     */
    protected function applyRetentionBoost(float $combinedScore, float $retentionScore): float
    {
        $floor = 0.5;
        $multiplier = $floor + ((1.0 - $floor) * $retentionScore);

        return $combinedScore * $multiplier;
    }
}
