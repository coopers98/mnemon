<?php

namespace Tests\Feature\Mcp;

use App\Models\Drawer;
use App\Models\McpClientRestriction;
use App\Models\Room;
use App\Models\User;
use App\Models\Wing;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Tests\TestCase;

class DeviceFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        VerifyCsrfToken::except(['oauth/device/authorize', 'oauth/token', 'oauth/device/code']);
    }

    private function deviceClient(): Client
    {
        return app(ClientRepository::class)
            ->createDeviceAuthorizationGrantClient('claude-code@testbox');
    }

    public function test_the_user_code_screen_renders(): void
    {
        // Was a 500: Passport 13 ships no device views and Mnemon bound only the
        // auth-code consent view, so resolving DeviceUserCodeViewResponse threw a
        // BindingResolutionException. This is the first screen a person reaches
        // when enrolling a machine, so the whole flow was unreachable.
        $this->get('/oauth/device')
            ->assertStatus(200)
            ->assertSee('code');
    }

    public function test_device_code_response_has_the_documented_shape(): void
    {
        $client = $this->deviceClient();

        $r = $this->postJson('/oauth/device/code', [
            'client_id' => $client->id,
            'client_secret' => $client->plainSecret,
            'scope' => 'mcp:use',
        ]);

        $r->assertStatus(200);
        $this->assertMatchesRegularExpression('/^[BCDFGHJKLMNPQRSTVWXZ]{8}$/', $r->json('user_code'));
        $this->assertSame(5, $r->json('interval'));
        $this->assertLessThanOrEqual(600, $r->json('expires_in'));
        $this->assertStringContainsString('/oauth/device?user_code=', $r->json('verification_uri_complete'));
        $this->assertNotEmpty($r->json('device_code'));
    }

    public function test_wrong_user_code_redirects_back_with_an_error(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/oauth/device/authorize?user_code=WRONGCOD')
            ->assertRedirect(route('passport.device'))
            ->assertSessionHasErrors('user_code');
    }

    public function test_consent_screen_requires_login(): void
    {
        $this->deviceClient();

        $this->get('/oauth/device/authorize?user_code=BCDFGHJK')->assertRedirect('/login');
    }

    public function test_full_device_flow_enforces_the_consented_wings(): void
    {
        $work = Wing::factory()->create(['slug' => 'work']);
        $personal = Wing::factory()->create(['slug' => 'personal']);
        Drawer::factory()->create([
            'room_id' => Room::factory()->create(['wing_id' => $work->id])->id,
            'content' => 'work-secret',
        ]);
        Drawer::factory()->create([
            'room_id' => Room::factory()->create(['wing_id' => $personal->id])->id,
            'content' => 'personal-secret',
        ]);

        $client = $this->deviceClient();
        $device = $this->requestDeviceCode($client);
        $poll = $this->pollPayload($client, $device['device_code']);

        // Nothing has been approved yet.
        $this->postJson('/oauth/token', $poll)
            ->assertStatus(400)
            ->assertJsonPath('error', 'authorization_pending');

        // A device that ignores the 5s interval is told to back off rather than
        // being served (RFC 8628 §3.5). Both sides of this comparison use the
        // real clock, so it holds without travelling.
        $this->postJson('/oauth/token', $poll)
            ->assertStatus(400)
            ->assertJsonPath('error', 'slow_down');

        // The screen names the machine and the code, so the person approving can
        // tell this request apart from one they did not make.
        $this->actingAs(User::factory()->create());
        $this->get('/oauth/device/authorize?user_code='.$device['user_code'])
            ->assertStatus(200)
            ->assertSee('claude-code@testbox')
            ->assertSee($device['user_code']);

        $this->post('/oauth/device/authorize', [
            'auth_token' => app('session.store')->get('authToken'),
            'wings' => ['work'],
        ])->assertRedirect(route('passport.device'))
            ->assertSessionHas('status', 'authorization-approved');

        // Consent is persisted keyed on the client, synchronously — the device
        // has no token yet, so there is nothing else to key it on.
        $this->assertSame(['work'], McpClientRestriction::find($client->id)?->wing_patterns);

        $this->travel(6)->seconds();
        $tokens = $this->postJson('/oauth/token', $poll);
        $tokens->assertStatus(200);
        $this->assertNotEmpty($tokens->json('refresh_token'));

        // The device code is spent and cannot be replayed for a second device.
        $this->travel(6)->seconds();
        $this->postJson('/oauth/token', $poll)->assertStatus(400);

        // And the restriction actually bites on /mcp.
        $r = $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'drawer_search', 'arguments' => ['query' => 'secret']],
        ], ['Authorization' => 'Bearer '.$tokens->json('access_token')]);

        $contents = collect($r->json('result.structuredContent.results'))->pluck('content')->all();
        $this->assertContains('work-secret', $contents);
        $this->assertNotContains('personal-secret', $contents,
            'the wings chosen on the device consent screen must restrict the token it mints');
    }

    public function test_all_wings_on_the_device_screen_writes_an_unrestricted_row(): void
    {
        Wing::factory()->create(['slug' => 'work']);
        $client = $this->deviceClient();
        $device = $this->requestDeviceCode($client);

        $this->actingAs(User::factory()->create());
        $this->get('/oauth/device/authorize?user_code='.$device['user_code'])->assertStatus(200);

        $this->post('/oauth/device/authorize', [
            'auth_token' => app('session.store')->get('authToken'),
            'all_wings' => '1',
        ])->assertSessionHas('status', 'authorization-approved');

        $restriction = McpClientRestriction::find($client->id);
        $this->assertNotNull($restriction, 'a row is written even when nothing is restricted');
        $this->assertNull($restriction->wing_patterns, 'null is the unrestricted shape; [] would deny everything');
    }

    public function test_denied_consent_yields_access_denied_and_writes_nothing(): void
    {
        Wing::factory()->create(['slug' => 'work']);
        $client = $this->deviceClient();
        $device = $this->requestDeviceCode($client);
        $poll = $this->pollPayload($client, $device['device_code']);

        $this->actingAs(User::factory()->create());
        $this->get('/oauth/device/authorize?user_code='.$device['user_code'])->assertStatus(200);

        // Deny is the same URI with DELETE spoofed, which is exactly why the
        // consent-capture middleware's POST match never fires for it.
        $this->post('/oauth/device/authorize', [
            '_method' => 'DELETE',
            'auth_token' => app('session.store')->get('authToken'),
            'wings' => ['work'],
        ])->assertRedirect(route('passport.device'))
            ->assertSessionHas('status', 'authorization-denied');

        $this->assertNull(McpClientRestriction::find($client->id),
            'a denial must not leave a consent record behind');

        // League answers a denied grant with 401, not the 400 the other poll
        // errors use — OAuthServerException::accessDenied carries 401.
        $this->travel(6)->seconds();
        $this->postJson('/oauth/token', $poll)
            ->assertStatus(401)
            ->assertJsonPath('error', 'access_denied');
    }

    public function test_a_forged_device_approval_writes_nothing(): void
    {
        // The device approve form carries no client_id, so the client comes from
        // the session's device code and any authenticated POST at this URI names
        // a real client. Two independent guards stop it: the auth token must
        // match the session, and the row is only written once Passport has
        // actually redirected an approval. Removing either alone still blocks
        // this; it takes removing both to write a row.
        Wing::factory()->create(['slug' => 'work']);
        $client = $this->deviceClient();
        $device = $this->requestDeviceCode($client);

        $this->actingAs(User::factory()->create());
        $this->get('/oauth/device/authorize?user_code='.$device['user_code'])->assertStatus(200);

        $this->post('/oauth/device/authorize', [
            'auth_token' => 'not-the-session-token',
            'all_wings' => '1',
        ]);

        $this->assertNull(McpClientRestriction::find($client->id),
            'a forged device approval must not grant this client any wings');
    }

    public function test_expired_device_code_yields_expired_token(): void
    {
        $client = $this->deviceClient();
        $device = $this->requestDeviceCode($client);

        // DeviceCodeGrant compares against time(), the real clock, which travel()
        // does not move — so age the stored code instead. That exercises the
        // grant's own expiry branch rather than a mocked Carbon.
        Passport::deviceCode()->newQuery()->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/oauth/token', $this->pollPayload($client, $device['device_code']))
            ->assertStatus(400)
            ->assertJsonPath('error', 'expired_token');
    }

    /**
     * @return array<string, mixed>
     */
    private function requestDeviceCode(Client $client): array
    {
        $r = $this->postJson('/oauth/device/code', [
            'client_id' => $client->id,
            'client_secret' => $client->plainSecret,
            'scope' => 'mcp:use',
        ]);
        $r->assertStatus(200);

        return $r->json();
    }

    /**
     * @return array<string, string>
     */
    private function pollPayload(Client $client, string $deviceCode): array
    {
        return [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'device_code' => $deviceCode,
            'client_id' => $client->id,
            'client_secret' => $client->plainSecret,
        ];
    }
}
