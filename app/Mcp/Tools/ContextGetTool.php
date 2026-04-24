<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use App\Mcp\McpException;
use App\Models\ApiKey;
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

        $wordCount = $page->content ? str_word_count($page->content) : 0;

        $result = [
            'id' => $page->id,
            'name' => $page->name,
            'type' => $page->type,
            'title' => $page->title,
            'description' => $page->description,
            'content' => $page->content,
            'last_compiled_at' => $page->last_compiled_at?->toIso8601String(),
            'word_count' => $wordCount,
        ];

        $this->logSession('context_get', $apiKey, ['name' => $name], 1);

        return $result;
    }
}
