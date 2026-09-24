<?php

namespace Tests\Feature;

use App\Models\BrainSession;
use App\Models\User;
use App\Services\ConnectedDeviceReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

/**
 * `source` is a display string — "claude-code@dogfood as me@example.com
 * (token …53b3)". Because it embeds the token id and tokens refresh hourly,
 * grouping by it made one machine look like sixteen devices, and there was no
 * way to ask "what is connected, and when did it last call in".
 *
 * `device` carries the bare name so that question is a grouped query.
 */
class ConnectedDevicesTest extends TestCase
{
    use MakesMcpRequests, RefreshDatabase;

    public function test_a_logged_call_records_the_bare_device_name(): void
    {
        $this->mcpCall('brain_status', []);

        $row = BrainSession::latest('id')->first();

        $this->assertNotNull($row);
        $this->assertSame('Test Client', $row->device);
        $this->assertStringContainsString('Test Client', $row->source);
        $this->assertStringContainsString('(token', $row->source, 'source keeps its full display form');
    }

    public function test_the_backfill_collapses_rows_fragmented_by_token_refresh(): void
    {
        // Exactly the shape the live instance accumulated: one machine, one row
        // per hourly token, each with a different suffix.
        foreach (['53b3', 'bdf4', '1c21'] as $suffix) {
            DB::table('brain_sessions')->insert([
                'tool_name' => 'recall',
                'source' => "claude-code@dogfood as me@example.com (token …{$suffix})",
                'input' => json_encode([]),
                'result_count' => 1,
                'created_at' => now(),
                'device' => null,
            ]);
        }

        (new ConnectedDeviceReport)->backfill();

        $devices = BrainSession::whereNotNull('device')->distinct()->pluck('device');

        $this->assertCount(1, $devices, 'three tokens from one machine must collapse to one device');
        $this->assertSame('claude-code@dogfood', $devices->first());
    }

    public function test_the_report_groups_by_device_with_a_last_seen(): void
    {
        DB::table('brain_sessions')->insert([
            ['tool_name' => 'recall', 'source' => 'alpha', 'device' => 'alpha', 'input' => json_encode([]), 'result_count' => 1, 'created_at' => now()->subDays(2)],
            ['tool_name' => 'recall', 'source' => 'alpha', 'device' => 'alpha', 'input' => json_encode([]), 'result_count' => 1, 'created_at' => now()],
            ['tool_name' => 'recall', 'source' => 'beta', 'device' => 'beta', 'input' => json_encode([]), 'result_count' => 1, 'created_at' => now()->subDays(40)],
        ]);

        $rows = (new ConnectedDeviceReport)->rows()->keyBy('device');

        $this->assertSame(2, $rows->get('alpha')['calls']);
        $this->assertSame('active', $rows->get('alpha')['status']);
        $this->assertSame('stale', $rows->get('beta')['status'], '40 days without a call is stale');
        $this->assertNotNull($rows->get('alpha')['last_seen']);
    }

    public function test_a_provisioned_device_that_never_connected_is_listed_as_never_seen(): void
    {
        // The failure you most want to catch when wiring a new machine: the
        // credential exists, the device never actually called in. An
        // activity-only view shows nothing, which looks the same as not looking.
        DB::table('oauth_clients')->insert([
            // oauth_clients.id is a uuid column on PostgreSQL; a readable
            // placeholder passes on SQLite and fails the pgsql matrix leg.
            'id' => (string) Str::uuid(),
            'name' => 'claude-code@newlaptop',
            'secret' => 'x',
            'provider' => 'users',
            'redirect_uris' => json_encode([]),
            'grant_types' => json_encode(['urn:ietf:params:oauth:grant-type:device_code']),
            'revoked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = (new ConnectedDeviceReport)->rows()->keyBy('device');

        $this->assertTrue($rows->has('claude-code@newlaptop'));
        $this->assertSame('never seen', $rows->get('claude-code@newlaptop')['status']);
        $this->assertNull($rows->get('claude-code@newlaptop')['last_seen']);
        $this->assertSame(0, $rows->get('claude-code@newlaptop')['calls']);
    }

    public function test_the_admin_page_renders_the_roster(): void
    {
        DB::table('brain_sessions')->insert([
            'tool_name' => 'recall',
            'source' => 'claude-code@samwise as me@example.com (token …aaaa)',
            'device' => 'claude-code@samwise',
            'input' => json_encode([]),
            'result_count' => 1,
            'created_at' => now(),
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin/connected-devices')
            ->assertOk()
            ->assertSee('claude-code@samwise')
            ->assertSee('active');
    }
}
