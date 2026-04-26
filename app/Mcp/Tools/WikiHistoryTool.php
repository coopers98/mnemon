<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use App\Mcp\McpException;
use App\Models\ApiKey;
use App\Models\WikiPage;

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

        $result = [
            'name' => $page->name,
            'revision_count' => $page->revision_count ?? 1,
            'previous_content_hash' => $page->previous_content_hash,
            'last_compiled_at' => $page->last_compiled_at?->toIso8601String(),
            'last_accessed_at' => $page->last_accessed_at?->toIso8601String(),
            'confidence_score' => $page->confidence_score,
            'source_count' => $page->source_count,
        ];

        $this->logSession('wiki_history', $apiKey, ['name' => $name], 1);

        return $result;
    }
}
