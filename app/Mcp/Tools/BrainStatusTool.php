<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresScope;
use App\Mcp\Support\BrainSessionLogger;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPage;
use App\Models\Wing;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Aggregate counts and configuration of the Mnemon brain.')]
#[IsReadOnly]
class BrainStatusTool extends Tool
{
    use RequiresScope;

    protected string $name = 'brain_status';

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($err = $this->requireScope($request)) {
            return $err;
        }

        $staleDays = (int) config('mnemon.wiki.stale_days', 30);
        $staleThreshold = now()->subDays($staleDays);

        $staleWikiPages = WikiPage::where(function ($query) use ($staleThreshold) {
            $query->whereNull('last_compiled_at')
                ->orWhere('last_compiled_at', '<', $staleThreshold);
        })->orderBy('last_compiled_at')->get()->map(fn ($p) => [
            'name' => $p->name,
            'last_compiled_at' => $p->last_compiled_at?->toIso8601String(),
        ])->values()->all();

        $pendingUpdatePages = WikiPage::pendingUpdates()
            ->get()
            ->map(fn ($p) => [
                'name' => $p->name,
                'pending_drawers_since_compile' => $p->pending_drawers_since_compile,
                'last_compiled_at' => $p->last_compiled_at?->toIso8601String(),
            ])->values()->all();

        $drawersByTier = Drawer::query()
            ->selectRaw("COALESCE(tier, 'raw') as tier, count(*) as count")
            ->groupBy('tier')
            ->pluck('count', 'tier')
            ->toArray();

        $lastWrite = Drawer::orderByDesc('created_at')->value('created_at');

        $wingsWithCounts = Wing::withCount(['rooms as drawer_count' => function ($query) {
            $query->join('drawers', 'drawers.room_id', '=', 'rooms.id')
                ->whereNull('drawers.deleted_at');
        }])->get()->map(fn ($w) => [
            'name' => $w->name,
            'slug' => $w->slug,
            'drawer_count' => (int) $w->drawer_count,
        ])->values()->all();

        $payload = [
            'wings' => Wing::count(),
            'rooms' => Room::count(),
            'drawers' => Drawer::count(),
            'wiki_pages' => WikiPage::count(),
            'drawers_by_tier' => $drawersByTier,
            'wings_detail' => $wingsWithCounts,
            'last_write' => $lastWrite ? $lastWrite->toIso8601String() : null,
            'stale_wiki_pages' => $staleWikiPages,
            'pending_update_pages' => $pendingUpdatePages,
            'embedding' => [
                'driver' => config('mnemon.embedding.driver'),
                'dimensions' => config('mnemon.embedding.dimensions'),
            ],
        ];

        BrainSessionLogger::log($request, 'brain_status', [], 1);

        return Response::structured($payload);
    }

    public function schema(JsonSchema $s): array
    {
        return [];
    }
}
