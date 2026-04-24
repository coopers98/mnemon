<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use App\Mcp\McpException;
use App\Models\ApiKey;
use App\Models\WikiPage;

class ContextListTool extends BaseTool
{
    public function requiredScope(): string
    {
        return 'wiki:read';
    }

    public function execute(array $params, ApiKey $apiKey): array
    {
        $this->requireScope($apiKey, $this->requiredScope());

        $type = $params['type'] ?? 'all';

        $validTypes = ['person', 'project', 'concept', 'decision', 'synthesis', 'all'];
        if (! in_array($type, $validTypes, true)) {
            throw McpException::invalidParams(
                'Parameter "type" must be one of: person, project, concept, decision, synthesis, all.'
            );
        }

        $query = WikiPage::orderBy('name');

        if ($type !== 'all') {
            $query->where('type', $type);
        }

        $pages = $query->get()->map(fn ($p) => [
            'name' => $p->name,
            'type' => $p->type,
            'title' => $p->title,
            'description' => $p->description,
            'last_compiled_at' => $p->last_compiled_at?->toIso8601String(),
            'word_count' => $p->content ? str_word_count($p->content) : 0,
        ])->values()->all();

        $this->logSession('context_list', $apiKey, ['type' => $type], count($pages));

        return ['pages' => $pages];
    }
}
