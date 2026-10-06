<?php

namespace App\Services;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\SessionDigest;
use App\Models\WikiPendingWing;
use App\Models\Wing;
use Illuminate\Database\QueryException;
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
     * @param  string|null  $project  The project the session ran in, as the harness names it
     *                                (its repository). When given, it decides the session's
     *                                project wing; the model no longer guesses it.
     */
    public function run(
        string $sessionId,
        string $harness,
        array $turnRange,
        string $transcript,
        array $recentDrawerIds,
        ?array $allowedWingPatterns,
        ?string $project = null,
    ): array {
        // A slice already digested is returned as-is rather than digested again.
        // The capture hook can dispatch the same range twice -- two workers
        // racing, or a session replaying a range after a resume -- and each
        // dispatch otherwise costs a reader call and duplicates drawers.
        if ($replay = $this->replayFor($sessionId, $turnRange)) {
            return $replay;
        }

        $floor = (float) config('mnemon.digest.confidence_floor', 0.5);

        $existingWings = $this->wingsForToken($allowedWingPatterns);
        $existingRooms = Room::query()
            ->whereIn('wing_id', collect($existingWings)->pluck('id'))
            ->get(['slug', 'wing_id']);
        $recentDrawers = Drawer::whereIn('id', $recentDrawerIds)->get(['id', 'content']);
        $sessionWingSlug = $project !== null
            ? $this->sessionWingSlug($project, $existingWings, $allowedWingPatterns)
            : null;

        $proposals = $this->llm->digest($transcript, [
            'turn_range' => $turnRange,
            'existing_wings' => collect($existingWings)->pluck('slug')->all(),
            'existing_rooms_per_wing' => $existingRooms->groupBy('wing_id')
                ->map(fn ($rs) => $rs->pluck('slug')->all())->all(),
            'recent_drawers' => $recentDrawers->map(fn ($d) => [
                'id' => $d->id,
                'snippet' => mb_substr($d->content, 0, 200),
            ])->all(),
            'session_wing' => $sessionWingSlug,
        ]);

        $persisted = [];
        $pendingWings = [];

        foreach ($proposals as $p) {
            if (($p['confidence'] ?? 0) < $floor) {
                continue;
            }

            if ($sessionWingSlug !== null && $this->isProjectFiling($p)) {
                $p = $this->refileToSessionWing($p, $sessionWingSlug);
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

        $this->recordSlice($sessionId, $turnRange, $persisted);

        return [
            'proposals' => $proposals,
            'persisted' => $persisted,
            'queued_for_review' => [],
            'pending_wings' => $pendingWings,
        ];
    }

    /**
     * The wing a session's project-specific drawers belong in, or null to leave
     * the choice to the model.
     *
     * Before this, the model picked the wing from the transcript alone and was
     * wrong most of the time: in the busiest wings most digested drawers came
     * from other projects. A project with no wing yet gets one -- proposing a
     * new wing only queued it for a review nobody did, so the model squeezed
     * unknown projects into whatever wing looked closest. A wing-restricted
     * token never creates wings and never reaches outside its wings.
     *
     * @param  array<int, Wing>  $tokenWings
     * @param  array<string>|null  $patterns
     */
    private function sessionWingSlug(string $project, array $tokenWings, ?array $patterns): ?string
    {
        $wing = Wing::forProject($project);

        if ($wing !== null) {
            $visible = collect($tokenWings)->contains(fn (Wing $w) => $w->id === $wing->id);

            return $visible ? $wing->slug : null;
        }

        $name = Wing::slugify($project);

        return ($patterns === null && $name !== '') ? "project-{$name}" : null;
    }

    /**
     * A proposal filed under a project wing, or under a wing the model made up,
     * belongs to the session's project. Person, decision and other standing
     * wings are cross-project, so the model's choice there stands.
     *
     * @param  array<string, mixed>  $p
     */
    private function isProjectFiling(array $p): bool
    {
        $slug = Wing::slugify((string) ($p['wing_slug'] ?? ''));

        return str_starts_with($slug, 'project-')
            || ! empty($p['propose_new_wing'])
            || ! Wing::where('slug', $slug)->exists();
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function refileToSessionWing(array $p, string $wingSlug): array
    {
        $wing = Wing::firstOrCreate(
            ['slug' => $wingSlug],
            ['name' => 'project:'.Str::after($wingSlug, 'project-')],
        );

        $room = Wing::slugify((string) ($p['room_slug'] ?? ''));
        $roomExists = $room !== '' && Room::where('wing_id', $wing->id)->where('slug', $room)->exists();

        return array_merge($p, [
            'wing_slug' => $wing->slug,
            'room_slug' => $roomExists ? $room : 'notes',
            'propose_new_wing' => false,
            // `notes` is the default room; the drawer writer creates it on demand.
            'propose_new_room' => ! $roomExists,
        ]);
    }

    /**
     * The result of a slice that has already been digested, or null.
     *
     * @param  array{start: int, end: int}  $turnRange
     * @return array<string, mixed>|null
     */
    private function replayFor(string $sessionId, array $turnRange): ?array
    {
        $digest = SessionDigest::query()
            ->where('session_id', $sessionId)
            ->where('turn_start', $turnRange['start'])
            ->where('turn_end', $turnRange['end'])
            ->first();

        if ($digest === null) {
            return null;
        }

        // Report the drawers the first run produced. The caller feeds these
        // back as recent_drawer_ids, so an empty list would lose that context.
        $persisted = Drawer::query()
            ->whereIn('id', $digest->drawer_ids)
            ->with('room.wing')
            ->get()
            ->map(fn (Drawer $drawer) => [
                'id' => $drawer->id,
                'wing' => $drawer->room?->wing?->slug,
                'room' => $drawer->room?->slug,
            ])
            ->all();

        return [
            'proposals' => [],
            'persisted' => $persisted,
            'queued_for_review' => [],
            'pending_wings' => [],
            'replayed' => true,
        ];
    }

    /**
     * @param  array{start: int, end: int}  $turnRange
     * @param  array<int, array{id: int}>  $persisted
     */
    private function recordSlice(string $sessionId, array $turnRange, array $persisted): void
    {
        try {
            SessionDigest::create([
                'session_id' => $sessionId,
                'turn_start' => $turnRange['start'],
                'turn_end' => $turnRange['end'],
                'drawer_ids' => collect($persisted)->pluck('id')->all(),
            ]);
        } catch (QueryException) {
            // A concurrent worker recorded the same slice first. The unique
            // index is the arbiter; losing the race is not an error.
        }
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
