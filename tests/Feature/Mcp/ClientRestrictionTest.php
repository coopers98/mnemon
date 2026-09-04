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
}
