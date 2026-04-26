<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use App\Mcp\McpException;
use App\Models\ApiKey;
use App\Models\Drawer;
use App\Models\WikiPage;
use App\Models\WikiPageRevision;
use App\Services\KnowledgeGraphService;
use App\Services\QualityScoreService;
use Illuminate\Support\Facades\DB;

class ContextSetTool extends BaseTool
{
    public function requiredScope(): string
    {
        return 'wiki:write';
    }

    public function execute(array $params, ApiKey $apiKey): array
    {
        $this->requireScope($apiKey, $this->requiredScope());

        $name = $params['name'] ?? null;
        $content = $params['content'] ?? null;

        if (empty($name)) {
            throw McpException::invalidParams('Parameter "name" is required.');
        }

        if (empty($content)) {
            throw McpException::invalidParams('Parameter "content" is required.');
        }

        $description = $params['description'] ?? null;
        $confidence = $params['confidence'] ?? null;
        $sources = $params['sources'] ?? null;
        $related = $params['related'] ?? null;
        $expectedRevision = isset($params['expected_revision']) ? (int) $params['expected_revision'] : null;
        $agentId = $params['agent_id'] ?? null;

        if ($confidence !== null && ! in_array($confidence, WikiPage::CONFIDENCE_LEVELS, true)) {
            throw McpException::invalidParams(
                'Parameter "confidence" must be one of: high, medium, low.'
            );
        }

        if ($sources !== null) {
            if (! is_array($sources)) {
                throw McpException::invalidParams('Parameter "sources" must be an array of drawer IDs.');
            }
            foreach ($sources as $id) {
                if (! is_int($id)) {
                    throw McpException::invalidParams(
                        'All elements in "sources" must be integers. Got: '.gettype($id)
                    );
                }
            }
            $sources = array_values(array_unique($sources));
            $existingCount = Drawer::whereIn('id', $sources)->count();
            if ($existingCount !== count($sources)) {
                throw McpException::invalidParams('One or more source drawer IDs do not exist.');
            }
        }

        if ($related !== null) {
            if (! is_array($related)) {
                throw McpException::invalidParams('Parameter "related" must be an array of wiki page names.');
            }
            foreach ($related as $r) {
                if (! is_string($r) || trim($r) === '') {
                    throw McpException::invalidParams(
                        'All elements in "related" must be non-empty strings.'
                    );
                }
            }
        }

        $type = $this->inferType($name);

        $existing = WikiPage::withTrashed()->where('name', $name)->first();

        // Fix: Restore soft-deleted pages on re-create
        if ($existing !== null && $existing->trashed()) {
            $existing->restore();
        }

        $createdOrUpdated = $existing === null ? 'created' : 'updated';

        // Item 15: Multi-agent conflict detection
        if ($existing !== null && $expectedRevision !== null) {
            $currentRevision = $existing->revision_count ?? 1;
            if ($currentRevision !== $expectedRevision) {
                throw new McpException(
                    "Conflict: page '{$name}' was modified since expected revision {$expectedRevision} "
                    .'(current revision: '.$currentRevision.'). '
                    .'Fetch the current page with context_get, merge your changes, then retry with expected_revision='.$currentRevision,
                    -32010,
                    409
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

        // Item 2: Supersession — track content hash and revision count
        $previousContent = null;
        $previousRevision = null;
        if ($existing !== null) {
            $previousContent = $existing->content ?? '';
            $previousRevision = $existing->revision_count ?? 1;
            $attributes['previous_content_hash'] = hash('sha256', $previousContent);
            $attributes['revision_count'] = $previousRevision + 1;
        } else {
            // Initialize revision_count=1 on new pages for consistent state
            $attributes['revision_count'] = 1;
        }

        // Wrap optimistic locking + write + revision insert in a transaction
        // to prevent concurrent agents from writing the same revision number
        $page = DB::transaction(function () use ($name, $attributes, $content, $agentId, $apiKey, $sources, $existing) {
            // Re-check revision inside transaction for true optimistic locking
            if ($existing !== null && isset($attributes['revision_count'])) {
                $freshRevision = WikiPage::where('name', $name)->lockForUpdate()->value('revision_count') ?? 1;
                $expectedPrev = $attributes['revision_count'] - 1;
                if ($freshRevision !== $expectedPrev) {
                    throw new McpException(
                        "Conflict: page '{$name}' was modified concurrently (expected revision {$expectedPrev}, found {$freshRevision}). "
                        .'Fetch the current page with context_get, merge your changes, then retry.',
                        -32010,
                        409
                    );
                }
            }

            $page = WikiPage::updateOrCreate(
                ['name' => $name],
                $attributes
            );

            // Recalculate confidence score
            $page->update(['confidence_score' => $page->calculateConfidenceScore()]);

            // Item 12: Quality scoring (heuristics or LLM — LLM path gated by config)
            $qualityService = app(QualityScoreService::class);
            $page->update(['quality_score' => $qualityService->score($content)]);

            // Item 3: Mark source drawers as consolidated
            if (! empty($sources)) {
                Drawer::whereIn('id', $sources)->update(['tier' => 'consolidated']);
            }

            // Item 15: Store revision in audit log
            WikiPageRevision::create([
                'page_name' => $page->name,
                'revision' => $page->revision_count ?? 1,
                'content' => $content,
                'content_hash' => hash('sha256', $content),
                'agent_id' => $agentId ?? $apiKey->name,
                'written_at' => now(),
            ]);

            return $page;
        });

        // Extract entity from page name prefix (e.g., person:cooper → Entity type=person)
        $graphService = app(KnowledgeGraphService::class);
        $entity = $graphService->extractEntity($page);

        // Wire related array into knowledge graph edges
        if (! empty($page->related)) {
            $graphService->syncReferencesFromRelated($page);
        }

        $this->updateWikiIndex($apiKey);
        $this->appendToWikiLog($name, $type, $createdOrUpdated, $apiKey);

        $result = [
            'page_id' => $page->id,
            'created_or_updated' => $createdOrUpdated,
            'name' => $page->name,
            'type' => $page->type,
            'confidence_score' => $page->confidence_score,
            'quality_score' => $page->quality_score,
            'revision_count' => $page->revision_count,
        ];

        $this->logSession('context_set', $apiKey, [
            'name' => $name,
            'type' => $type,
        ], 1);

        return $result;
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

    private function updateWikiIndex(ApiKey $apiKey): void
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

    private function appendToWikiLog(string $name, string $type, string $action, ApiKey $apiKey): void
    {
        $logPage = WikiPage::where('name', 'wiki/log')->first();
        $timestamp = now()->toIso8601String();
        $entry = "- {$timestamp} | {$action} | `{$name}` ({$type}) by {$apiKey->name}";

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
