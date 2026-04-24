<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use App\Models\ApiKey;
use App\Models\Drawer;
use App\Models\WikiPage;
use App\Models\Wing;

class BrainStatusTool extends BaseTool
{
    public function requiredScope(): string
    {
        return 'palace:read';
    }

    public function execute(array $params, ApiKey $apiKey): array
    {
        $this->requireScope($apiKey, $this->requiredScope());

        $drawerCount = Drawer::count();
        $wikiPageCount = WikiPage::count();

        $wings = Wing::withCount(['rooms as drawer_count' => function ($query) {
            $query->join('drawers', 'drawers.room_id', '=', 'rooms.id')
                ->whereNull('drawers.deleted_at');
        }])->get()->map(fn ($w) => [
            'name' => $w->name,
            'slug' => $w->slug,
            'drawer_count' => (int) $w->drawer_count,
        ])->values()->all();

        $embeddingDriver = config('mnemon.embedding.driver', 'none');

        $lastWrite = Drawer::orderByDesc('created_at')->value('created_at');

        $staleDays = (int) config('mnemon.wiki.stale_days', 30);
        $staleThreshold = now()->subDays($staleDays);

        $staleWikiPages = WikiPage::where(function ($query) use ($staleThreshold) {
            $query->whereNull('last_compiled_at')
                ->orWhere('last_compiled_at', '<', $staleThreshold);
        })->orderBy('last_compiled_at')->get()->map(fn ($p) => [
            'name' => $p->name,
            'last_compiled_at' => $p->last_compiled_at?->toIso8601String(),
        ])->values()->all();

        $result = [
            'drawer_count' => $drawerCount,
            'wiki_page_count' => $wikiPageCount,
            'wings' => $wings,
            'embedding_driver' => $embeddingDriver,
            'last_write' => $lastWrite ? $lastWrite->toIso8601String() : null,
            'stale_wiki_pages' => $staleWikiPages,
        ];

        $this->logSession('brain_status', $apiKey, $params, 1);

        return $result;
    }
}
