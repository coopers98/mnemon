<?php

namespace App\Services;

use Illuminate\Support\Collection;

class DrawerSearchService
{
    public function __construct(
        protected PalaceSearchService $palace,
    ) {}

    /**
     * Run a hybrid search across drawers, optionally scoped to a wing and/or
     * restricted to a set of allowed wing patterns.
     *
     * @param  array<string>|null  $allowedWingPatterns  null = unrestricted
     * @return array<int, array<string, mixed>>
     */
    public function run(
        string $query,
        int $limit = 10,
        ?string $wing = null,
        ?array $allowedWingPatterns = null,
    ): array {
        $results = $this->palace->search(
            query: $query,
            wing: $wing,
            room: null,
            limit: $limit,
            mode: 'hybrid',
        );

        // Defense-in-depth: filter results to allowed wings even when no explicit
        // wing filter was passed (restricted tokens must not see other wings).
        if ($allowedWingPatterns !== null) {
            $results = $results->filter(
                fn ($r) => $this->wingMatchesPatterns($r->wing_slug, $allowedWingPatterns)
            );
        }

        return $results->values()->map(fn ($r) => [
            'id'         => $r->id,
            'content'    => $r->content,
            'wing'       => $r->wing,
            'wing_slug'  => $r->wing_slug,
            'room'       => $r->room,
            'room_slug'  => $r->room_slug,
            'source'     => $r->source,
            'metadata'   => $r->metadata,
            'tier'       => $r->tier ?? 'raw',
            'created_at' => $r->created_at,
            'score'      => $r->score,
        ])->all();
    }

    /**
     * Check whether a wing slug matches any of the given patterns.
     *
     * @param  array<string>  $patterns
     */
    protected function wingMatchesPatterns(string $wingSlug, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === $wingSlug) {
                return true;
            }
            if (str_contains($pattern, '*')) {
                $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/';
                if (preg_match($regex, $wingSlug)) {
                    return true;
                }
            }
        }

        return false;
    }
}
