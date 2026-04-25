<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use App\Mcp\McpException;
use App\Models\ApiKey;
use App\Models\Drawer;
use App\Models\WikiPage;

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

        $existing = WikiPage::where('name', $name)->first();
        $createdOrUpdated = $existing === null ? 'created' : 'updated';

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
        }
        if ($related !== null) {
            $attributes['related'] = $related;
        }

        $page = WikiPage::updateOrCreate(
            ['name' => $name],
            $attributes
        );

        $this->updateWikiIndex($apiKey);
        $this->appendToWikiLog($name, $type, $createdOrUpdated, $apiKey);

        $result = [
            'page_id' => $page->id,
            'created_or_updated' => $createdOrUpdated,
            'name' => $page->name,
            'type' => $page->type,
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
