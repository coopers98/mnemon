<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use App\Mcp\McpException;
use App\Models\ApiKey;
use App\Models\Drawer;
use App\Models\WikiPage;

class ContextGetTool extends BaseTool
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
            $this->logSession('context_get', $apiKey, ['name' => $name], 0);

            return [
                'error' => 'Wiki page not found',
                'name' => $name,
            ];
        }

        // Update last_accessed_at on read
        $page->update(['last_accessed_at' => now()]);

        $wordCount = $page->content ? str_word_count($page->content) : 0;

        $result = [
            'id' => $page->id,
            'name' => $page->name,
            'type' => $page->type,
            'title' => $page->title,
            'description' => $page->description,
            'confidence' => $page->confidence,
            'sources' => $page->sources,
            'related' => $page->related,
        ];

        // Only include source_details if the API key has palace:read scope
        if (! empty($page->sources) && $apiKey->hasScope('palace:read')) {
            $sourceDetails = [];
            $drawers = Drawer::whereIn('id', $page->sources)->get();
            foreach ($drawers as $drawer) {
                $sourceDetails[] = [
                    'id' => $drawer->id,
                    'content_preview' => mb_substr($drawer->content, 0, 200),
                    'source' => $drawer->source,
                ];
            }
            $result['source_details'] = $sourceDetails;
        }

        $result += [
            'confidence_score' => $page->confidence_score,
            'source_count' => $page->source_count,
            'pending_drawers_since_compile' => $page->pending_drawers_since_compile,
            'revision_count' => $page->revision_count,
            'content' => $page->content,
            'last_compiled_at' => $page->last_compiled_at?->toIso8601String(),
            'last_accessed_at' => $page->last_accessed_at?->toIso8601String(),
            'word_count' => $wordCount,
        ];

        $this->logSession('context_get', $apiKey, ['name' => $name], 1);

        return $result;
    }
}
