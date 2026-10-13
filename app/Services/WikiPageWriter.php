<?php

namespace App\Services;

use App\Models\Drawer;
use App\Models\WikiPage;
use App\Models\WikiPageRevision;
use Illuminate\Support\Facades\DB;

/**
 * Writes one revision of a wiki page: the page row and its scores, a revision
 * audit row, the consolidation of its source drawers, and its graph edges.
 *
 * Moved out of ContextSetTool so the demo seeder builds pages exactly the way
 * agents do. The tool keeps what is MCP-specific: validation, wing access, the
 * expected_revision check, the wiki index and log pages, and session logging.
 */
class WikiPageWriter
{
    public function __construct(
        protected QualityScoreService $quality,
        protected KnowledgeGraphService $graph,
    ) {}

    /**
     * @param  array<int>|null  $sources  Drawer ids the caller has already validated.
     * @param  array<string>|null  $related  Related page names.
     * @param  int|null  $expectedRevision  The revision the caller read; checked again under the row lock.
     *
     * @throws \RuntimeException when another writer changed the page concurrently.
     */
    public function write(
        string $name,
        string $content,
        string $agent,
        ?string $description = null,
        ?string $confidence = null,
        ?array $sources = null,
        ?array $related = null,
        ?int $expectedRevision = null,
    ): WikiPage {
        $existing = WikiPage::where('name', $name)->first();

        $attributes = [
            'title' => $existing?->title ?? $name,
            'content' => $content,
            'type' => self::typeFor($name),
            'description' => $description ?? ($existing?->description),
            'last_compiled_at' => now(),
            'pending_drawers_since_compile' => 0,
        ];

        if ($confidence !== null) {
            $attributes['confidence'] = $confidence;
        }
        if ($sources !== null) {
            $attributes['sources'] = $sources;
            $attributes['source_count'] = count($sources);
        }
        if ($related !== null) {
            $attributes['related'] = $related;
        }

        // Supersession — track content hash and revision count
        if ($existing !== null) {
            $attributes['previous_content_hash'] = hash('sha256', $existing->content ?? '');
            $attributes['revision_count'] = ($existing->revision_count ?? 1) + 1;
        } else {
            // Initialize revision_count=1 on new pages for consistent state
            $attributes['revision_count'] = 1;
        }

        // Wrap optimistic locking + write + revision insert in a transaction
        // to prevent concurrent agents from writing the same revision number
        $page = DB::transaction(function () use ($name, $attributes, $content, $agent, $sources, $existing, $expectedRevision) {
            // Re-check revision inside transaction for true optimistic locking
            if ($existing !== null) {
                $freshRevision = WikiPage::where('name', $name)->lockForUpdate()->value('revision_count') ?? 1;
                $expectedPrev = $expectedRevision ?? $attributes['revision_count'] - 1;
                if ($freshRevision !== $expectedPrev) {
                    throw new \RuntimeException(
                        "Conflict: page '{$name}' was modified concurrently (expected revision {$expectedPrev}, found {$freshRevision}). "
                        .'Fetch the current page with context_get, merge your changes, then retry.'
                    );
                }
            }

            $page = WikiPage::updateOrCreate(
                ['name' => $name],
                $attributes
            );

            // Recalculate confidence score
            $page->update(['confidence_score' => $page->calculateConfidenceScore()]);

            // Quality scoring (heuristics or LLM — LLM path gated by config)
            $page->update(['quality_score' => $this->quality->score($content)]);

            // Mark source drawers as consolidated
            if (! empty($sources)) {
                Drawer::whereIn('id', $sources)->update(['tier' => 'consolidated']);
            }

            // Store revision in audit log
            WikiPageRevision::create([
                'page_name' => $page->name,
                'revision' => $page->revision_count ?? 1,
                'content' => $content,
                'content_hash' => hash('sha256', $content),
                'agent_id' => $agent,
                'written_at' => now(),
            ]);

            return $page;
        });

        // Extract entity from page name prefix (e.g., person:cooper → Entity type=person)
        $this->graph->extractEntity($page);

        // Wire related array into knowledge graph edges
        if (! empty($page->related)) {
            $this->graph->syncReferencesFromRelated($page);
        }

        return $page;
    }

    /** The page type a name's prefix implies; no prefix means synthesis. */
    public static function typeFor(string $name): string
    {
        $prefixes = [
            'person:' => 'person',
            'project:' => 'project',
            'concept:' => 'concept',
            'decision:' => 'decision',
        ];

        foreach ($prefixes as $prefix => $type) {
            if (str_starts_with($name, $prefix)) {
                return $type;
            }
        }

        return 'synthesis';
    }
}
