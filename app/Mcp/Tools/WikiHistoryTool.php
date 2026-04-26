<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use App\Mcp\McpException;
use App\Models\ApiKey;
use App\Models\WikiPage;
use App\Models\WikiPageRevision;

class WikiHistoryTool extends BaseTool
{
    public function requiredScope(): string
    {
        return 'wiki:read';
    }

    public function execute(array $params, ApiKey $apiKey): array
    {
        $this->requireScope($apiKey, $this->requiredScope());

        $name = $params['name'] ?? null;

        if (empty($name)) {
            throw McpException::invalidParams('Parameter "name" is required.');
        }

        $page = WikiPage::where('name', $name)->first();

        if ($page === null) {
            $this->logSession('wiki_history', $apiKey, ['name' => $name], 0);

            return [
                'error' => 'Wiki page not found',
                'name' => $name,
            ];
        }

        $limit = max(1, min(50, (int) ($params['limit'] ?? 10)));

        $revisions = WikiPageRevision::where('page_name', $name)
            ->orderByDesc('revision')
            ->limit($limit)
            ->get()
            ->map(fn (WikiPageRevision $r) => [
                'revision' => $r->revision,
                'content_hash' => $r->content_hash,
                'agent_id' => $r->agent_id,
                'written_at' => $r->written_at?->toIso8601String(),
            ])
            ->all();

        $result = [
            'name' => $page->name,
            'revision_count' => $page->revision_count ?? 1,
            'previous_content_hash' => $page->previous_content_hash,
            'last_compiled_at' => $page->last_compiled_at?->toIso8601String(),
            'last_accessed_at' => $page->last_accessed_at?->toIso8601String(),
            'confidence_score' => $page->confidence_score,
            'quality_score' => $page->quality_score,
            'source_count' => $page->source_count,
            'revisions' => $revisions,
        ];

        $this->logSession('wiki_history', $apiKey, ['name' => $name], 1);

        return $result;
    }
}
