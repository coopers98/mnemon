<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use App\Mcp\McpException;
use App\Models\ApiKey;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPage;
use App\Models\Wing;
use Illuminate\Support\Str;

class WikiCompileTool extends BaseTool
{
    public function requiredScope(): string
    {
        return 'palace:read';
    }

    public function execute(array $params, ApiKey $apiKey): array
    {
        $this->requireScope($apiKey, 'palace:read');

        $name = $params['name'] ?? null;
        $limit = $params['limit'] ?? 20;

        if (empty($name)) {
            throw McpException::invalidParams('Parameter "name" is required.');
        }

        $limit = max(1, min((int) $limit, 100));

        // Convert wiki page name to wing slug
        // e.g. `project:atlas-abs` → `project-atlas-abs`
        $wingSlug = Str::slug(str_replace(':', '-', $name));

        $this->requireWingAccess($apiKey, $wingSlug);

        $wing = Wing::where('slug', $wingSlug)->first();

        if ($wing === null) {
            $this->logSession('wiki_compile', $apiKey, ['name' => $name], 0);

            return [
                'error' => "No wing found matching page name '{$name}' (looked for slug '{$wingSlug}')",
                'name' => $name,
            ];
        }

        $roomIds = Room::where('wing_id', $wing->id)->pluck('id');

        $drawerModels = Drawer::whereIn('room_id', $roomIds)
            ->orderByDesc('created_at')
            ->take($limit)
            ->get();

        // Item 3: Mark raw drawers as reviewed when gathered for compilation
        $rawDrawerIds = $drawerModels->where('tier', 'raw')->pluck('id');
        if ($rawDrawerIds->isNotEmpty()) {
            Drawer::whereIn('id', $rawDrawerIds)->update(['tier' => 'reviewed']);
        }

        $drawers = $drawerModels->map(fn ($d) => [
            'id' => $d->id,
            'content' => $d->content,
            'source' => $d->source,
            'tier' => $d->tier,
            'created_at' => $d->created_at->toIso8601String(),
        ])->values()->all();

        $page = WikiPage::where('name', $name)->first();

        $result = [
            'page' => $page ? [
                'name' => $page->name,
                'current_content' => $page->content,
                'last_compiled_at' => $page->last_compiled_at?->toIso8601String(),
                'confidence' => $page->confidence,
            ] : null,
            'drawers' => $drawers,
            'drawer_count' => count($drawers),
            'wing' => [
                'name' => $wing->name,
                'slug' => $wing->slug,
            ],
        ];

        $this->logSession('wiki_compile', $apiKey, ['name' => $name, 'limit' => $limit], count($drawers));

        return $result;
    }
}
