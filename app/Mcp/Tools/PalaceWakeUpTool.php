<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresScope;
use App\Mcp\Concerns\RequiresWingAccess;
use App\Mcp\Support\BrainSessionLogger;
use App\Models\Drawer;
use App\Models\WikiPage;
use App\Models\Wing;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Seed agent context with recent palace activity: latest drawers, active wings, and wiki status.')]
#[IsReadOnly]
class PalaceWakeUpTool extends Tool
{
    use RequiresScope, RequiresWingAccess;

    protected string $name = 'palace_wake_up';

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($err = $this->requireScope($request)) {
            return $err;
        }

        $patterns = $this->wingPatternsFor($request);

        // Recent drawers (wing-filtered when restrictions apply)
        $drawerQuery = Drawer::with('room.wing')->latest('created_at')->limit(10);

        if ($patterns !== null) {
            $drawerQuery->whereHas('room.wing', function ($q) use ($patterns) {
                $q->where(function ($inner) use ($patterns) {
                    foreach ($patterns as $p) {
                        $inner->orWhere('slug', 'like', str_replace('*', '%', $p));
                    }
                });
            });
        }

        $recentDrawers = $drawerQuery->get()->map(fn ($d) => [
            'id' => $d->id,
            'content' => mb_substr($d->content, 0, 200),
            'wing' => $d->room->wing->name ?? null,
            'wing_slug' => $d->room->wing->slug ?? null,
            'room' => $d->room->name ?? null,
            'room_slug' => $d->room->slug ?? null,
            'source' => $d->source,
            'created_at' => $d->created_at->toIso8601String(),
        ])->values()->all();

        // Active wings (wing-filtered when restrictions apply)
        $wingQuery = Wing::select('wings.*')
            ->join('rooms', 'rooms.wing_id', '=', 'wings.id')
            ->join('drawers', function ($join) {
                $join->on('drawers.room_id', '=', 'rooms.id')
                    ->whereNull('drawers.deleted_at');
            })
            ->selectRaw('MAX(drawers.created_at) as latest_drawer_at')
            ->groupBy('wings.id')
            ->orderByDesc('latest_drawer_at');

        if ($patterns !== null) {
            $wingQuery->where(function ($q) use ($patterns) {
                foreach ($patterns as $p) {
                    $q->orWhere('wings.slug', 'like', str_replace('*', '%', $p));
                }
            });
        }

        $activeWings = $wingQuery->get()->map(fn ($w) => [
            'name' => $w->name,
            'slug' => $w->slug,
            'latest_drawer_at' => $w->latest_drawer_at,
        ])->values()->all();

        // Recent wiki updates (wiki pages are not wing-scoped — no filtering)
        $recentWikiUpdates = WikiPage::where('updated_at', '>=', now()->subDays(7))
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn ($p) => [
                'name' => $p->name,
                'type' => $p->type,
                'title' => $p->title,
                'updated_at' => $p->updated_at->toIso8601String(),
            ])->values()->all();

        // Stale wiki pages
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

        // Pages with pending updates
        $pendingUpdatePages = WikiPage::pendingUpdates()
            ->get()
            ->map(fn ($p) => [
                'name' => $p->name,
                'type' => $p->type,
                'pending_drawers_since_compile' => $p->pending_drawers_since_compile,
                'last_compiled_at' => $p->last_compiled_at?->toIso8601String(),
            ])->values()->all();

        $payload = [
            'greeting' => 'Welcome to Mnemon. Your palace is ready.',
            'recent_drawers' => $recentDrawers,
            'active_wings' => $activeWings,
            'recent_wiki_updates' => $recentWikiUpdates,
            'stale_wiki_pages' => $staleWikiPages,
            'pending_update_pages' => $pendingUpdatePages,
        ];

        BrainSessionLogger::log($request, 'palace_wake_up', [], count($recentDrawers));

        return Response::structured($payload);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
