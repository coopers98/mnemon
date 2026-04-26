<?php

namespace App\Services;

use App\Services\Embeddings\NullDriver;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PalaceSearchService
{
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
                drawers.created_at,
                wings.name AS wing,
                wings.slug AS wing_slug,
                rooms.name AS room,
                rooms.slug AS room_slug,
                (1 - (drawers.embedding <=> ?::vector)) AS raw_score
            ', [$vectorLiteral])
            ->orderByRaw('drawers.embedding <=> ?::vector', [$vectorLiteral])
            ->limit($limit)
            ->get();

        return $this->normalizeAndWrap($rows, 'raw_score');
    }

    /**
     * Full-text search. Uses tsvector on Postgres, LIKE fallback on SQLite.
     */
    protected function fulltextSearch(
        string $query,
        ?string $wing,
        ?string $room,
        int $limit,
        ?string $tier = null,
    ): Collection {
        if ($this->isPostgres()) {
            return $this->postgresFulltext($query, $wing, $room, $limit, $tier);
        }

        return $this->sqliteFulltext($query, $wing, $room, $limit, $tier);
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

            $fulltextResults = $this->postgresFulltext($query, $wing, $room, $limit * 3, $tier);
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

                $finalScore = ($semScore * $semanticWeight)
                    + ($ftScore * $fulltextWeight)
                    + ($temporal * $temporalWeight);

                return (object) [
                    'id' => $base->id,
                    'content' => $base->content,
                    'wing' => $base->wing,
                    'wing_slug' => $base->wing_slug,
                    'room' => $base->room,
                    'room_slug' => $base->room_slug,
                    'source' => $base->source,
                    'metadata' => $base->metadata,
                    'tier' => $base->tier ?? 'raw',
                    'created_at' => $base->created_at,
                    'score' => round($finalScore, 6),
                ];
            });
        } else {
            // SQLite: fulltext + temporal only
            $fulltextWeight = (float) ($weights['fulltext'] ?? 0.3);

            $fulltextResults = $this->sqliteFulltext($query, $wing, $room, $limit * 3, $tier);

            $combined = $fulltextResults->map(function ($row) use ($fulltextWeight, $temporalWeight, $boostDays) {
                $ftScore = (float) $row->score;
                $temporal = $this->temporalBoost($row->created_at, $boostDays);

                $finalScore = ($ftScore * $fulltextWeight) + ($temporal * $temporalWeight);

                return (object) [
                    'id' => $row->id,
                    'content' => $row->content,
                    'wing' => $row->wing,
                    'wing_slug' => $row->wing_slug,
                    'room' => $row->room,
                    'room_slug' => $row->room_slug,
                    'source' => $row->source,
                    'metadata' => $row->metadata,
                    'tier' => $row->tier ?? 'raw',
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

    protected function postgresFulltext(
        string $query,
        ?string $wing,
        ?string $room,
        int $limit,
        ?string $tier = null,
    ): Collection {
        $base = $this->baseQuery($wing, $room, $tier);
        $rows = $base
            ->selectRaw("
                drawers.id,
                drawers.content,
                drawers.source,
                drawers.metadata,
                drawers.tier,
                drawers.created_at,
                wings.name AS wing,
                wings.slug AS wing_slug,
                rooms.name AS room,
                rooms.slug AS room_slug,
                ts_rank(to_tsvector('english', drawers.content), plainto_tsquery('english', ?)) AS raw_score
            ", [$query])
            ->whereRaw("to_tsvector('english', drawers.content) @@ plainto_tsquery('english', ?)", [$query])
            ->orderByRaw('raw_score DESC')
            ->limit($limit)
            ->get();

        return $this->normalizeAndWrap($rows, 'raw_score');
    }

    protected function sqliteFulltext(
        string $query,
        ?string $wing,
        ?string $room,
        int $limit,
        ?string $tier = null,
    ): Collection {
        $words = array_values(array_filter(
            array_unique(explode(' ', preg_replace('/\s+/', ' ', strtolower(trim($query))))),
            fn ($w) => strlen($w) > 0
        ));

        if (empty($words)) {
            return collect();
        }

        $base = $this->baseQuery($wing, $room, $tier);

        // Build LIKE conditions for each word and count matches per word
        $conditions = [];
        $selectBindings = [];
        $whereConditions = [];
        $whereBindings = [];
        foreach ($words as $word) {
            $conditions[] = 'CASE WHEN LOWER(drawers.content) LIKE ? THEN 1 ELSE 0 END';
            $selectBindings[] = '%'.$word.'%';
            $whereConditions[] = 'LOWER(drawers.content) LIKE ?';
            $whereBindings[] = '%'.$word.'%';
        }

        $scoreSql = '('.implode(' + ', $conditions).')';
        $matchSql = '('.implode(' OR ', $whereConditions).')';

        $rows = $base
            ->selectRaw("
                drawers.id,
                drawers.content,
                drawers.source,
                drawers.metadata,
                drawers.tier,
                drawers.created_at,
                wings.name AS wing,
                wings.slug AS wing_slug,
                rooms.name AS room,
                rooms.slug AS room_slug,
                {$scoreSql} AS raw_score
            ", $selectBindings)
            ->whereRaw($matchSql, $whereBindings)
            ->orderByRaw("{$scoreSql} DESC", $selectBindings)
            ->limit($limit)
            ->get();

        return $this->normalizeAndWrap($rows, 'raw_score', count($words));
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
     */
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
                'metadata' => $row->metadata,
                'tier' => $row->tier ?? 'raw',
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
}
