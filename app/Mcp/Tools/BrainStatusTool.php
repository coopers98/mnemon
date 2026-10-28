<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresScope;
use App\Mcp\Concerns\RequiresWingAccess;
use App\Mcp\Support\BrainSessionLogger;
use App\Models\Drawer;
use App\Models\LlmModel;
use App\Models\Room;
use App\Models\WikiPage;
use App\Models\Wing;
use App\Services\Digest\OpenAiDigestDriver;
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

        $params = $request->validate([
            'projects' => 'nullable|array|max:500',
            'projects.*' => 'string|max:100',
        ]);

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
        $readableWingIds = Wing::readableIds($patterns);
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

        if ($readableWingIds !== null) {
            $wingsQuery = $wingsQuery->whereIn('id', $readableWingIds)->values();
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
            'wiki_pages' => $patterns === null ? WikiPage::count() : WikiPage::readableSubset(WikiPage::get(['name']), $patterns)->count(),
            'drawers_by_tier' => $drawersByTier,
            'wings_detail' => $wingsWithCounts,
            'last_write' => $lastWrite ? $lastWrite->toIso8601String() : null,
            'stale_wiki_pages' => $staleWikiPages,
            'pending_update_pages' => $pendingUpdatePages,
            'embedding' => $this->embeddingStatus($drawers),
            'digest' => $this->digestStatus($readableWingIds, $params['projects'] ?? null),
        ];

        BrainSessionLogger::log($request, 'brain_status', [], 1);

        return Response::structured($payload);
    }

    public function schema(JsonSchema $s): array
    {
        return [
            'projects' => $s->array()->description('Optional project names (up to 500). The digest block then says which visible wing each resolves to, or null when it has none.'),
        ];
    }

    /**
     * What a client needs to price a digest before sending any: the model, its
     * prices (null until configured), the fixed prompt cost for THIS token's
     * wings, and where its project names would be filed.
     *
     * @param  array<int>|null  $readableWingIds  Null for an unrestricted token.
     * @param  array<int, string>|null  $projects
     * @return array<string, mixed>
     */
    private function digestStatus(?array $readableWingIds, ?array $projects): array
    {
        $driver = new OpenAiDigestDriver;

        $wings = Wing::query()
            ->when($readableWingIds !== null, fn ($q) => $q->whereIn('id', $readableWingIds))
            ->get(['id', 'slug']);
        $rooms = Room::query()->whereIn('wing_id', $wings->pluck('id'))->get(['slug', 'wing_id']);

        // The context SessionDigestService hands the driver, minus the
        // per-call parts: recent drawers are empty here and added back at
        // their worst case (50 snippets of 200 characters) below.
        $prompt = $driver->systemPrompt([
            'existing_wings' => $wings->pluck('slug')->all(),
            'existing_rooms_per_wing' => $rooms->groupBy('wing_id')->map(fn ($rs) => $rs->pluck('slug')->all())->all(),
            'recent_drawers' => [],
        ]);

        // Prices are the model's own row in the admin panel; a model without
        // one, or without a price entered, publishes null.
        $model = $driver->model();
        $row = LlmModel::for($driver->provider(), $model);
        $price = fn (?string $v) => $v === null ? null : (float) $v;

        $digest = [
            'model' => $model,
            'price_in_per_mtok' => $price($row?->price_in_per_mtok),
            'price_cached_in_per_mtok' => $price($row?->price_cached_in_per_mtok),
            'price_out_per_mtok' => $price($row?->price_out_per_mtok),
            'prompt_overhead_tokens' => (int) ceil((mb_strlen($prompt) + 50 * 200) / 4),
            'typical_output_tokens' => (int) config('mnemon.digest.typical_output_tokens', 3000),
            'max_completion_tokens' => (int) config('mnemon.digest.max_completion_tokens', 16000),
        ];

        if ($projects !== null) {
            // An existing wing this token can see, or null. A hidden wing and
            // a missing one both answer null.
            $resolved = [];
            foreach ($projects as $name) {
                $wing = Wing::forProject($name, $wings->pluck('id')->all());
                $resolved[$name] = $wing?->slug;
            }
            $digest['projects'] = (object) $resolved;
        }

        return $digest;
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
