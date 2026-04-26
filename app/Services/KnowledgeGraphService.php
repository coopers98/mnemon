<?php

namespace App\Services;

use App\Models\Entity;
use App\Models\EntityRelationship;
use App\Models\WikiPage;

class KnowledgeGraphService
{
    /**
     * Traverse the knowledge graph starting from a page, up to max_depth hops.
     *
     * Returns a subgraph: nodes (page names + metadata) and edges (typed relationships).
     *
     * @param  string[]|null  $edgeTypes  Filter to specific edge types; null = all
     */
    public function traverse(string $startPage, int $maxDepth = 2, ?array $edgeTypes = null): array
    {
        $visited = [];
        $nodes = [];
        $edges = [];

        $this->bfs($startPage, $maxDepth, $edgeTypes, $visited, $nodes, $edges);

        return [
            'start' => $startPage,
            'max_depth' => $maxDepth,
            'node_count' => count($nodes),
            'edge_count' => count($edges),
            'nodes' => array_values($nodes),
            'edges' => array_values($edges),
        ];
    }

    /**
     * Hard cap on visited nodes to prevent runaway traversals.
     */
    private const MAX_NODES = 100;

    private function bfs(
        string $startPage,
        int $maxDepth,
        ?array $edgeTypes,
        array &$visited,
        array &$nodes,
        array &$edges
    ): void {
        // BFS processes nodes level-by-level so we can batch-load relationships per depth.
        $currentLevel = [$startPage];

        for ($depth = 0; $depth <= $maxDepth; $depth++) {
            $toVisit = [];
            foreach ($currentLevel as $pageName) {
                if (isset($visited[$pageName]) || count($visited) >= self::MAX_NODES) {
                    continue;
                }
                $visited[$pageName] = true;
                $toVisit[] = $pageName;
            }

            if (empty($toVisit)) {
                break;
            }

            // Batch-load wiki pages for this depth level
            $pages = WikiPage::whereIn('name', $toVisit)->get()->keyBy('name');

            foreach ($toVisit as $pageName) {
                $page = $pages->get($pageName);
                if ($page === null) {
                    continue;
                }

                $nodes[$pageName] = [
                    'name' => $page->name,
                    'type' => $page->type,
                    'title' => $page->title,
                    'confidence_score' => $page->confidence_score,
                    'quality_score' => $page->quality_score,
                ];
            }

            // Stop expanding edges at the max depth
            if ($depth >= $maxDepth) {
                break;
            }

            // Batch-load relationships for all pages at this depth
            $query = EntityRelationship::where(function ($q) use ($toVisit) {
                $q->whereIn('from_page', $toVisit)
                    ->orWhereIn('to_page', $toVisit);
            });

            if (! empty($edgeTypes)) {
                $query->whereIn('edge_type', $edgeTypes);
            }

            $relationships = $query->get();

            $nextLevel = [];
            foreach ($relationships as $rel) {
                $edgeKey = "{$rel->from_page}:{$rel->edge_type}:{$rel->to_page}";

                if (! isset($edges[$edgeKey])) {
                    $edges[$edgeKey] = [
                        'from' => $rel->from_page,
                        'to' => $rel->to_page,
                        'type' => $rel->edge_type,
                        'description' => $rel->description,
                    ];
                }

                // Determine which side is the "next" page to explore
                $fromInLevel = in_array($rel->from_page, $toVisit, true);
                $toInLevel = in_array($rel->to_page, $toVisit, true);

                if ($fromInLevel && ! isset($visited[$rel->to_page])) {
                    $nextLevel[] = $rel->to_page;
                }
                if ($toInLevel && ! isset($visited[$rel->from_page])) {
                    $nextLevel[] = $rel->from_page;
                }
            }

            $currentLevel = array_unique($nextLevel);

            if (count($visited) >= self::MAX_NODES) {
                break;
            }
        }
    }

    /**
     * Upsert a typed edge between two wiki pages.
     */
    public function addEdge(string $fromPage, string $toPage, string $edgeType, ?string $description = null): EntityRelationship
    {
        if (! in_array($edgeType, EntityRelationship::EDGE_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid edge type: {$edgeType}");
        }

        return EntityRelationship::updateOrCreate(
            [
                'from_page' => $fromPage,
                'to_page' => $toPage,
                'edge_type' => $edgeType,
            ],
            ['description' => $description]
        );
    }

    /**
     * Extract a single Entity for a wiki page based on its name prefix.
     *
     * Heuristic: prefix maps to entity type (person:, project:, decision:, etc.).
     * Synthesis pages don't get entities — they're not first-class entities.
     */
    public function extractEntity(WikiPage $page): ?Entity
    {
        $type = $this->inferEntityType($page);
        if ($type === null) {
            return null;
        }

        return Entity::updateOrCreate(
            ['name' => $page->name],
            [
                'type' => $type,
                'source_page' => $page->name,
                'description' => $page->description,
            ]
        );
    }

    /**
     * Backfill `references` edges from a page's `related` array.
     *
     * Existing pages stored relationships in the `related` JSON column without
     * an edge type. We treat each entry as a generic `references` edge — callers
     * can later promote them to typed edges (uses, depends-on, etc.).
     *
     * @return int Count of edges created or updated
     */
    public function syncReferencesFromRelated(WikiPage $page): int
    {
        $related = $page->related ?? [];
        if (empty($related)) {
            return 0;
        }

        $count = 0;
        foreach ($related as $target) {
            if (! is_string($target) || trim($target) === '' || $target === $page->name) {
                continue;
            }

            // Only create edges to pages that actually exist
            if (! WikiPage::where('name', $target)->exists()) {
                continue;
            }

            $this->addEdge($page->name, $target, 'references');
            $count++;
        }

        return $count;
    }

    /**
     * Map a wiki page's name prefix to an Entity type.
     */
    private function inferEntityType(WikiPage $page): ?string
    {
        $prefixes = [
            'person:' => 'person',
            'project:' => 'project',
            'decision:' => 'decision',
            'system:' => 'system',
            'concept:' => 'concept',
        ];

        foreach ($prefixes as $prefix => $type) {
            if (str_starts_with($page->name, $prefix)) {
                return $type;
            }
        }

        return null;
    }
}
