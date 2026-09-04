<?php

namespace Tests\Feature\Mcp;

use App\Models\McpClientRestriction;
use App\Models\McpTokenRestriction;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Tests\TestCase;

class ClientRestrictionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        VerifyCsrfToken::except(['oauth/authorize', 'oauth/token']);
    }

    private function approveConsent(array $extra): Client
    {
        $user = User::factory()->create();
        $client = Client::factory()->create(['redirect_uris' => ['http://localhost/cb']]);
        $this->actingAs($user);

        return $this->approveFor($client, $extra);
    }

    private function approveFor(Client $client, array $extra): Client
    {
        $this->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->id, 'redirect_uri' => 'http://localhost/cb',
            'response_type' => 'code', 'scope' => 'mcp:use', 'state' => 'st',
        ]))->assertStatus(200);

        $this->post('/oauth/authorize', array_merge([
            'auth_token' => app('session.store')->get('authToken'),
            'client_id' => $client->id, 'scopes' => ['mcp:use'],
        ], $extra))->assertRedirect();

        return $client;
    }

    public function test_approve_writes_a_client_keyed_row_before_any_token_exists(): void
    {
        $client = $this->approveConsent(['wings' => ['work']]);

        $row = McpClientRestriction::find($client->id);
        $this->assertNotNull($row,
            'consent must persist synchronously in the approve request, not via a cache handoff');
        $this->assertSame(['work'], $row->wing_patterns);
        $this->assertSame(0, McpTokenRestriction::count(), 'no token-keyed row is written any more');
    }

    public function test_all_wings_writes_a_null_pattern_row(): void
    {
        $client = $this->approveConsent(['all_wings' => '1']);

        $this->assertNull(McpClientRestriction::find($client->id)->wing_patterns);
    }

    public function test_no_selection_without_all_wings_is_deny_all_not_unrestricted(): void
    {
        $client = $this->approveConsent(['wings' => []]);

        $row = McpClientRestriction::find($client->id);
        $this->assertSame([], $row->wing_patterns,
            'an empty selection must fail closed; the old null meant silently unrestricted');
        $this->assertFalse($row->matches('work'));
    }

    public function test_reconsent_updates_the_existing_row(): void
    {
        $client = $this->approveConsent(['wings' => ['work']]);
        $this->approveFor($client, ['all_wings' => '1']);

        $this->assertNull(McpClientRestriction::find($client->id)->wing_patterns);
        $this->assertSame(1, McpClientRestriction::count());
    }

    public function test_a_forged_approval_does_not_write_a_restriction(): void
    {
        // The middleware runs before Passport validates the approval, so without
        // a guard any authenticated POST naming a client_id could rewrite that
        // client's wings — including widening them to all — without ever
        // completing a genuine consent.
        $user = User::factory()->create();
        $client = Client::factory()->create(['redirect_uris' => ['http://localhost/cb']]);
        $this->actingAs($user);

        $this->post('/oauth/authorize', [
            'auth_token' => 'not-the-session-token',
            'client_id' => $client->id,
            'scopes' => ['mcp:use'],
            'all_wings' => '1',
        ]);

        $this->assertNull(McpClientRestriction::find($client->id),
            'a POST that Passport did not accept must not mutate restrictions');
    }

    public function test_a_forged_approval_cannot_widen_an_existing_restriction(): void
    {
        $client = $this->approveConsent(['wings' => ['work']]);
        $this->assertSame(['work'], McpClientRestriction::find($client->id)->wing_patterns);

        // Same session, but a forged token and an attempt to widen to all wings.
        $this->post('/oauth/authorize', [
            'auth_token' => 'not-the-session-token',
            'client_id' => $client->id,
            'scopes' => ['mcp:use'],
            'all_wings' => '1',
        ]);

        $this->assertSame(['work'], McpClientRestriction::find($client->id)->wing_patterns,
            'a rejected approval must not widen wings that a real consent narrowed');
    }

    public function test_an_unknown_client_id_writes_nothing(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->post('/oauth/authorize', [
            'auth_token' => app('session.store')->get('authToken') ?? 'x',
            'client_id' => '0199a1f4-1b2c-7000-8000-00000000dead',
            'scopes' => ['mcp:use'],
            'all_wings' => '1',
        ]);

        $this->assertSame(0, McpClientRestriction::count());
    }
}
