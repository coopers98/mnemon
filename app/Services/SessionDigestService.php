<?php

namespace App\Services;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPendingWing;
use App\Models\Wing;
use Illuminate\Support\Str;

class SessionDigestService
{
    /**
     * @param  object  $llm  Anything with a `digest(string $transcript, array $context): array` method.
     */
    public function __construct(
        protected object $llm,
        protected DrawerWriteService $drawerWriter,
    ) {}

    /**
     * @param  array{start:int,end:int}  $turnRange
     * @param  array<int>  $recentDrawerIds
     * @param  array<string>|null  $allowedWingPatterns
     */
    public function run(
        string $sessionId,
        string $harness,
        array $turnRange,
        string $transcript,
        array $recentDrawerIds,
        ?array $allowedWingPatterns,
    ): array {
        $floor = (float) config('mnemon.digest.confidence_floor', 0.5);

        $existingWings = $this->wingsForToken($allowedWingPatterns);
        $existingRooms = Room::query()
            ->whereIn('wing_id', collect($existingWings)->pluck('id'))
            ->get(['slug', 'wing_id']);
        $recentDrawers = Drawer::whereIn('id', $recentDrawerIds)->get(['id', 'content']);

        $proposals = $this->llm->digest($transcript, [
            'turn_range' => $turnRange,
            'existing_wings' => collect($existingWings)->pluck('slug')->all(),
            'existing_rooms_per_wing' => $existingRooms->groupBy('wing_id')
                ->map(fn ($rs) => $rs->pluck('slug')->all())->all(),
            'recent_drawers' => $recentDrawers->map(fn ($d) => [
                'id' => $d->id,
                'snippet' => mb_substr($d->content, 0, 200),
            ])->all(),
        ]);

        $persisted = [];
        $pendingWings = [];

        foreach ($proposals as $p) {
            if (($p['confidence'] ?? 0) < $floor) {
                continue;
            }

            if (! empty($p['propose_new_wing'])) {
                $row = WikiPendingWing::create([
                    'wing_slug' => $p['wing_slug'],
                    'wing_name' => Str::headline($p['wing_slug']),
                    'rationale' => $p['rationale'] ?? null,
                    'drawer_payload' => [
                        'content' => $p['content'],
                        'room_slug' => $p['room_slug'] ?? 'notes',
                        'source' => "{$harness}:session_digest",
                        'metadata' => [
                            'captured_via' => 'session_digest',
                            'harness' => $harness,
                            'session_id' => $sessionId,
                            'confidence' => $p['confidence'],
                        ],
                    ],
                    'proposed_by_session_id' => $sessionId,
                ]);
                $pendingWings[] = [
                    'id' => $row->id,
                    'wing_slug' => $row->wing_slug,
                    'rationale' => $row->rationale,
                ];

                continue;
            }

            // Existing-wing path. Wing must already exist — digest never auto-creates wings.
            $wing = Wing::where('slug', $p['wing_slug'])->first();
            if (! $wing) {
                continue;
            }

            $roomSlug = $p['room_slug'] ?? 'notes';
            $existingRoom = Room::where('slug', $roomSlug)->where('wing_id', $wing->id)->first();

            if (! $existingRoom) {
                if (empty($p['propose_new_room'])) {
                    continue;
                }
                // Pre-create the room with metadata so DrawerWriteService::createDrawer
                // finds it via firstOrCreate without overwriting the metadata flag.
                Room::create([
                    'wing_id' => $wing->id,
                    'slug' => $roomSlug,
                    'name' => Str::headline($roomSlug),
                    'metadata' => ['auto_created' => true, 'created_via' => 'session_digest'],
                ]);
            }

            $drawer = $this->drawerWriter->createDrawer(
                wingSlug: $wing->slug,
                roomSlug: $roomSlug,
                content: $p['content'],
                source: "{$harness}:session_digest",
                metadata: [
                    'captured_via' => 'session_digest',
                    'harness' => $harness,
                    'session_id' => $sessionId,
                    'confidence' => $p['confidence'],
                ],
            );

            $persisted[] = [
                'id' => $drawer->id,
                'wing' => $wing->slug,
                'room' => $roomSlug,
            ];
        }

        return [
            'proposals' => $proposals,
            'persisted' => $persisted,
            'queued_for_review' => [],
            'pending_wings' => $pendingWings,
        ];
    }

    /**
     * @param  array<string>|null  $patterns
     * @return array<int, Wing>
     */
    protected function wingsForToken(?array $patterns): array
    {
        if ($patterns === null) {
            return Wing::all()->all();
        }

        return Wing::all()
            ->filter(function (Wing $w) use ($patterns) {
                foreach ($patterns as $pattern) {
                    $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/';
                    if ($pattern === $w->slug || preg_match($regex, $w->slug)) {
                        return true;
                    }
                }

                return false;
            })
            ->values()
            ->all();
    }
}
