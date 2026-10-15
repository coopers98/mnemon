<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresScope;
use App\Mcp\Concerns\RequiresWingAccess;
use App\Mcp\Support\BrainSessionLogger;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPage;
use App\Models\Wing;
use App\Support\WingPatterns;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Schema;
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
    use RequiresScope, RequiresWingAccess;

    protected string $name = 'brain_status';

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($err = $this->requireScope($request)) {
            return $err;
        }

        $staleDays = (int) config('mnemon.wiki.stale_days', 30);
        $staleThreshold = now()->subDays($staleDays);

        // Page names alone are disclosure -- `person:jane-doe` says who exists.
        $patterns = $this->wingPatternsFor($request);

        $staleWikiPages = WikiPage::readableSubset(
            WikiPage::where(function ($query) use ($staleThreshold) {
                $query->whereNull('last_compiled_at')
                    ->orWhere('last_compiled_at', '<', $staleThreshold);
            })->orderBy('last_compiled_at')->get(),
            $patterns
        )->map(fn ($p) => [
            'name' => $p->name,
            'last_compiled_at' => $p->last_compiled_at?->toIso8601String(),
        ])->values()->all();

        $pendingUpdatePages = WikiPage::readableSubset(
            WikiPage::pendingUpdates()->get(),
            $patterns
        )
            ->map(fn ($p) => [
                'name' => $p->name,
                'pending_drawers_since_compile' => $p->pending_drawers_since_compile,
                'last_compiled_at' => $p->last_compiled_at?->toIso8601String(),
            ])->values()->all();

        // Counts are scoped to the token's wings too: totals across hidden
        // wings say how much is there and when it last changed.
        $readableWingIds = $patterns === null ? null : Wing::all()
            ->filter(fn (Wing $w) => WingPatterns::matches($w->slug, $patterns))
            ->pluck('id');
        $drawers = fn () => Drawer::query()->when(
            $readableWingIds !== null,
            fn ($q) => $q->whereHas('room', fn ($r) => $r->whereIn('wing_id', $readableWingIds))
        );

        $drawersByTier = $drawers()
            ->selectRaw("COALESCE(tier, 'raw') as tier, count(*) as count")
            ->groupBy('tier')
            ->pluck('count', 'tier')
            ->toArray();

        $lastWrite = $drawers()->orderByDesc('created_at')->value('created_at');

        // Not the documented wiki hole, but the same boundary: this listed every
        // wing by name to any token, so a `work`-restricted agent learned that
        // `personal` exists and how much is in it.
        $wingsQuery = Wing::withCount(['rooms as drawer_count' => function ($query) {
            $query->join('drawers', 'drawers.room_id', '=', 'rooms.id')
                ->whereNull('drawers.deleted_at');
        }])->get();

        if ($patterns !== null) {
            $wingsQuery = $wingsQuery->filter(
                fn (Wing $w) => WingPatterns::matches($w->slug, $patterns)
            )->values();
        }

        $wingsWithCounts = $wingsQuery->map(fn ($w) => [
            'name' => $w->name,
            'slug' => $w->slug,
            'drawer_count' => (int) $w->drawer_count,
        ])->values()->all();

        $payload = [
            'wings' => count($wingsWithCounts),
            'rooms' => Room::query()->when($readableWingIds !== null, fn ($q) => $q->whereIn('wing_id', $readableWingIds))->count(),
            'drawers' => $drawers()->count(),
            'wiki_pages' => WikiPage::readableSubset(WikiPage::get(['name']), $patterns)->count(),
            'drawers_by_tier' => $drawersByTier,
            'wings_detail' => $wingsWithCounts,
            'last_write' => $lastWrite ? $lastWrite->toIso8601String() : null,
            'stale_wiki_pages' => $staleWikiPages,
            'pending_update_pages' => $pendingUpdatePages,
            'embedding' => $this->embeddingStatus($drawers),
        ];

        BrainSessionLogger::log($request, 'brain_status', [], 1);

        return Response::structured($payload);
    }

    public function schema(JsonSchema $s): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function embeddingStatus(Closure $drawers): array
    {
        $driver = config('mnemon.embedding.driver');

        // The dimensions live under the driver, not at the top of the
        // embedding config — reading the top-level key always returned null.
        $dimensions = config("mnemon.embedding.drivers.{$driver}.dimensions");

        // The embedding column only exists on PostgreSQL (added via raw
        // DB::statement in the migrations); SQLite never gets the column.
        $hasColumn = Schema::hasColumn('drawers', 'embedding');
        $embedded = $hasColumn ? $drawers()->whereNotNull('embedding')->count() : 0;

        return [
            'driver' => $driver,
            'dimensions' => $dimensions,
            'embedded_drawers' => $embedded,
            'unembedded_drawers' => $drawers()->count() - $embedded,
        ];
    }
}
