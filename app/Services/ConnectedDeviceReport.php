<?php

namespace App\Services;

use App\Models\BrainSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "What is connected, and when did it last call in?"
 *
 * Two sources, deliberately: recorded activity answers what *is* connected, and
 * the device-grant OAuth clients answer what was *meant* to be. A device that
 * was provisioned but never successfully called in appears in the second and
 * not the first — and that is the failure worth surfacing, because an
 * activity-only list shows nothing for it, which looks identical to not having
 * checked.
 */
class ConnectedDeviceReport
{
    /** A call within this many days means the device is live. */
    public const ACTIVE_DAYS = 2;

    /** Beyond this, it has probably stopped working rather than gone quiet. */
    public const STALE_DAYS = 30;

    /**
     * Strip the display decoration from a `source` string.
     *
     * "claude-code@dogfood as me@example.com (token …53b3)" → "claude-code@dogfood"
     */
    public static function deviceFromSource(string $source): string
    {
        $name = preg_replace('/\s*\(token\s.*\)\s*$/u', '', $source) ?? $source;
        $name = preg_replace('/\s+as\s+\S+@\S+\s*$/u', '', $name) ?? $name;

        return trim($name);
    }

    /**
     * Populate `device` for rows written before the column existed.
     *
     * Chunked rather than one UPDATE: the parsing is a PHP regex, not something
     * expressible in portable SQL, and the suite runs on SQLite as well as
     * PostgreSQL.
     */
    public function backfill(): int
    {
        $updated = 0;

        BrainSession::whereNull('device')
            ->select(['id', 'source'])
            ->chunkById(500, function (Collection $rows) use (&$updated) {
                foreach ($rows as $row) {
                    BrainSession::where('id', $row->id)
                        ->update(['device' => static::deviceFromSource((string) $row->source)]);
                    $updated++;
                }
            });

        return $updated;
    }

    /**
     * One row per device, newest activity first, never-seen devices last.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(): Collection
    {
        $activity = BrainSession::query()
            ->whereNotNull('device')
            ->groupBy('device')
            ->select([
                'device',
                DB::raw('count(*) as calls'),
                DB::raw('max(created_at) as last_seen'),
                DB::raw('count(distinct tool_name) as tools'),
            ])
            ->get()
            ->keyBy('device');

        $rows = $activity->map(fn ($a) => $this->row(
            device: (string) $a->device,
            calls: (int) $a->calls,
            lastSeen: $a->last_seen,
            tools: (int) $a->tools,
        ))->values();

        // Provisioned but silent. Only clients carrying the device grant are
        // candidates — the personal-access client is not a device.
        $seen = $activity->keys()->all();

        $provisioned = DB::table('oauth_clients')
            ->whereNotIn('name', $seen)
            ->get(['id', 'name', 'revoked', 'grant_types']);

        foreach ($provisioned as $client) {
            if (! $this->isDeviceClient($client)) {
                continue;
            }

            $rows->push($this->row(
                device: (string) $client->name,
                calls: 0,
                lastSeen: null,
                tools: 0,
                revoked: (bool) $client->revoked,
            ));
        }

        return $rows->sortByDesc(fn ($r) => $r['last_seen'] ?? '')->values();
    }

    private function isDeviceClient(object $client): bool
    {
        $grants = $client->grant_types ?? null;

        if (is_string($grants)) {
            $grants = json_decode($grants, true);
        }

        return is_array($grants)
            && in_array('urn:ietf:params:oauth:grant-type:device_code', $grants, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $device, int $calls, mixed $lastSeen, int $tools, bool $revoked = false): array
    {
        return [
            'device' => $device,
            'calls' => $calls,
            'tools' => $tools,
            'last_seen' => $lastSeen,
            'status' => $this->status($lastSeen, $revoked),
        ];
    }

    private function status(mixed $lastSeen, bool $revoked): string
    {
        if ($revoked) {
            return 'revoked';
        }

        if ($lastSeen === null) {
            return 'never seen';
        }

        $age = now()->diffInDays(Carbon::parse($lastSeen), absolute: true);

        return match (true) {
            $age <= self::ACTIVE_DAYS => 'active',
            $age <= self::STALE_DAYS => 'idle',
            default => 'stale',
        };
    }
}
