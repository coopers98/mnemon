<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresScope;
use App\Mcp\Concerns\ResolvesAgentSource;
use App\Mcp\Support\BrainSessionLogger;
use App\Models\Drawer;
use App\Models\WikiPage;
use App\Models\WikiPageRevision;
use App\Services\KnowledgeGraphService;
use App\Services\QualityScoreService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Upsert a wiki page with optimistic locking and revision audit.')]
class ContextSetTool extends Tool
{
    use RequiresScope, ResolvesAgentSource;

    protected string $name = 'context_set';

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($err = $this->requireScope($request)) {
            return $err;
        }

        $params = $request->validate([
            'name' => 'required|string|max:255',
            'content' => 'required|string',
            'description' => 'nullable|string',
            'confidence' => 'nullable|string',
            'sources' => 'nullable|array',
            'sources.*' => 'integer',
            'related' => 'nullable|array',
            'related.*' => 'string',
            'expected_revision' => 'nullable|integer',
            'agent_id' => 'nullable|string',
        ]);

        $name = $params['name'];
        $content = $params['content'];
        $description = $params['description'] ?? null;
        $confidence = $params['confidence'] ?? null;
        $sources = $params['sources'] ?? null;
        $related = $params['related'] ?? null;
        $expectedRevision = isset($params['expected_revision']) ? (int) $params['expected_revision'] : null;
        $agentId = $params['agent_id'] ?? null;

        if ($confidence !== null && ! in_array($confidence, WikiPage::CONFIDENCE_LEVELS, true)) {
            return Response::error('Parameter "confidence" must be one of: high, medium, low.');
        }

        if ($sources !== null) {
            foreach ($sources as $id) {
                if (! is_int($id)) {
                    return Response::error('All elements in "sources" must be integers. Got: '.gettype($id));
                }
            }
            $sources = array_values(array_unique($sources));
            $existingCount = Drawer::whereIn('id', $sources)->count();
            if ($existingCount !== count($sources)) {
                return Response::error('One or more source drawer IDs do not exist.');
            }
        }

        if ($related !== null) {
            foreach ($related as $r) {
                if (! is_string($r) || trim($r) === '') {
                    return Response::error('All elements in "related" must be non-empty strings.');
                }
            }
        }

        $type = $this->inferType($name);

        $existing = WikiPage::withTrashed()->where('name', $name)->first();

        // Restore soft-deleted pages on re-create
        if ($existing !== null && $existing->trashed()) {
            $existing->restore();
        }

        $createdOrUpdated = $existing === null ? 'created' : 'updated';

        // Multi-agent conflict detection
        if ($existing !== null && $expectedRevision !== null) {
            $currentRevision = $existing->revision_count ?? 1;
            if ($currentRevision !== $expectedRevision) {
                return Response::error(
                    "Conflict: page '{$name}' was modified since expected revision {$expectedRevision} "
                    .'(current revision: '.$currentRevision.'). '
                    .'Fetch the current page with context_get, merge your changes, then retry with expected_revision='.$currentRevision
                );
            }
        }

        $attributes = [
            'title' => $existing?->title ?? $name,
            'content' => $content,
            'type' => $type,
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
            $previousContent = $existing->content ?? '';
            $attributes['previous_content_hash'] = hash('sha256', $previousContent);
            $attributes['revision_count'] = ($existing->revision_count ?? 1) + 1;
        } else {
            // Initialize revision_count=1 on new pages for consistent state
            $attributes['revision_count'] = 1;
        }

        // Resolve agent string once (used in revision row and wiki log)
        $resolvedAgent = $this->agentSource($request, $agentId);

        // Wrap optimistic locking + write + revision insert in a transaction
        // to prevent concurrent agents from writing the same revision number
        $page = DB::transaction(function () use ($name, $attributes, $content, $resolvedAgent, $sources, $existing) {
            // Re-check revision inside transaction for true optimistic locking
            if ($existing !== null && isset($attributes['revision_count'])) {
                $freshRevision = WikiPage::where('name', $name)->lockForUpdate()->value('revision_count') ?? 1;
                $expectedPrev = $attributes['revision_count'] - 1;
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
            $qualityService = app(QualityScoreService::class);
            $page->update(['quality_score' => $qualityService->score($content)]);

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
                'agent_id' => $resolvedAgent,
                'written_at' => now(),
            ]);

            return $page;
        });

        // Extract entity from page name prefix (e.g., person:cooper → Entity type=person)
        $graphService = app(KnowledgeGraphService::class);
        $graphService->extractEntity($page);

        // Wire related array into knowledge graph edges
        if (! empty($page->related)) {
            $graphService->syncReferencesFromRelated($page);
        }

        $this->updateWikiIndex();
        $this->appendToWikiLog($name, $type, $createdOrUpdated, $resolvedAgent);

        $result = [
            'page_id' => $page->id,
            'created_or_updated' => $createdOrUpdated,
            'name' => $page->name,
            'type' => $page->type,
            'confidence_score' => $page->confidence_score,
            'quality_score' => $page->quality_score,
            'revision_count' => $page->revision_count,
        ];

        BrainSessionLogger::log($request, 'context_set', [
            'name' => $name,
            'type' => $type,
        ], 1);

        return Response::structured($result);
    }

    public function schema(JsonSchema $s): array
    {
        return [
            'name' => $s->string()->required()->description('Wiki page name. Use a prefix for type inference (e.g. "person:alice", "project:beta", "concept:flow", "decision:arch"). No prefix defaults to "synthesis".'),
            'content' => $s->string()->required()->description('Full page content (Markdown).'),
            'description' => $s->string()->description('Short one-line summary of the page.'),
            'confidence' => $s->string()->description('Confidence level: high, medium, or low.'),
            'sources' => $s->array()->description('Array of palace drawer IDs this page was compiled from.'),
            'related' => $s->array()->description('Array of related wiki page names (graph edges).'),
            'expected_revision' => $s->integer()->description('Optimistic locking: current revision_count. Provide to detect concurrent modifications.'),
            'agent_id' => $s->string()->description('Agent identifier override for the revision audit log. Defaults to OAuth client name.'),
        ];
    }

    private function inferType(string $name): string
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

    private function updateWikiIndex(): void
    {
        $pages = WikiPage::orderBy('name')->get();

        $lines = ["# Wiki Index\n\nLast updated: ".now()->toIso8601String()."\n"];
        foreach ($pages as $p) {
            if ($p->name === 'wiki/index' || $p->name === 'wiki/log') {
                continue;
            }
            $title = $p->title ?? $p->name;
            $desc = $p->description ? ' — '.$p->description : '';
            $lines[] = "- **{$title}** (`{$p->name}`, {$p->type}){$desc}";
        }

        $indexContent = implode("\n", $lines);

        WikiPage::updateOrCreate(
            ['name' => 'wiki/index'],
            [
                'title' => 'Wiki Index',
                'content' => $indexContent,
                'type' => 'synthesis',
                'last_compiled_at' => now(),
            ]
        );
    }

    private function appendToWikiLog(string $name, string $type, string $action, string $agent): void
    {
        $logPage = WikiPage::where('name', 'wiki/log')->first();
        $timestamp = now()->toIso8601String();
        $entry = "- {$timestamp} | {$action} | `{$name}` ({$type}) by {$agent}";

        if ($logPage === null) {
            WikiPage::create([
                'name' => 'wiki/log',
                'title' => 'Wiki Log',
                'type' => 'synthesis',
                'content' => "# Wiki Log\n\n{$entry}",
                'last_compiled_at' => now(),
            ]);
        } else {
            $logPage->content = ($logPage->content ?? "# Wiki Log\n")."\n{$entry}";
            $logPage->last_compiled_at = now();
            $logPage->save();
        }
    }
}
