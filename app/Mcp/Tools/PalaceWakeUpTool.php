<?php

namespace App\Mcp\Tools;

use App\Mcp\BaseTool;
use App\Models\ApiKey;
use App\Models\Drawer;
use App\Models\WikiPage;
use App\Models\Wing;

class PalaceWakeUpTool extends BaseTool
{
    public function requiredScope(): string
    {
        return 'palace:read';
    }

    public function execute(array $params, ApiKey $apiKey): array
    {
        $this->requireScope($apiKey, $this->requiredScope());

        $recentDrawers = Drawer::with(['room.wing'])
            ->orderByDesc('created_at')
            ->take(10)
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'content_preview' => mb_substr($d->content, 0, 200),
                'wing' => $d->room->wing->name ?? null,
                'wing_slug' => $d->room->wing->slug ?? null,
                'room' => $d->room->name ?? null,
                'room_slug' => $d->room->slug ?? null,
                'source' => $d->source,
                'created_at' => $d->created_at->toIso8601String(),
            ])->values()->all();

        $activeWings = Wing::select('wings.*')
            ->join('rooms', 'rooms.wing_id', '=', 'wings.id')
            ->join('drawers', function ($join) {
                $join->on('drawers.room_id', '=', 'rooms.id')
                    ->whereNull('drawers.deleted_at');
            })
            ->selectRaw('MAX(drawers.created_at) as latest_drawer_at')
            ->groupBy('wings.id')
            ->orderByDesc('latest_drawer_at')
            ->get()
            ->map(fn ($w) => [
                'name' => $w->name,
                'slug' => $w->slug,
                'latest_drawer_at' => $w->latest_drawer_at,
            ])->values()->all();

        $recentWikiUpdates = WikiPage::where('updated_at', '>=', now()->subDays(7))
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn ($p) => [
                'name' => $p->name,
                'type' => $p->type,
                'title' => $p->title,
                'updated_at' => $p->updated_at->toIso8601String(),
            ])->values()->all();

        $staleDays = (int) config('mnemon.wiki.stale_days', 30);
        $staleThreshold = now()->subDays($staleDays);

        $staleWikiPages = WikiPage::where(function ($query) use ($staleThreshold) {
            $query->whereNull('last_compiled_at')
                ->orWhere('last_compiled_at', '<', $staleThreshold);
        })->orderBy('last_compiled_at')
            ->get()
            ->map(fn ($p) => [
                'name' => $p->name,
                'type' => $p->type,
                'last_compiled_at' => $p->last_compiled_at?->toIso8601String(),
            ])->values()->all();

        $result = [
            'recent_drawers' => $recentDrawers,
            'active_wings' => $activeWings,
            'recent_wiki_updates' => $recentWikiUpdates,
            'stale_wiki_pages' => $staleWikiPages,
        ];

        $this->logSession('palace_wake_up', $apiKey, $params, count($recentDrawers));

        return $result;
    }
}
