<?php

namespace Tests\Feature\Mcp;

use App\Models\McpTokenRestriction;
use App\Models\User;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Tests\TestCase;

class OAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Exempt the OAuth consent and token endpoints from CSRF so POSTs work in tests.
        \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::except([
            'oauth/authorize',
            'oauth/token',
        ]);
    }

    /**
     * Complete the full OAuth authorization code flow and return the access token.
     * This covers:
     *   1. GET /oauth/authorize  — show consent view, get authToken
     *   2. POST /oauth/authorize — approve consent, get redirect with auth code
     *   3. POST /oauth/token     — exchange code for access token (fires AccessTokenCreated)
     *
     * @param  array<string>  $wings     Per-wing slugs to restrict (empty = use all_wings)
     * @param  bool           $allWings  If true, grant access to all wings (no restriction)
     */
    private function completeOAuthFlow(
        Client $client,
        User $user,
        array $wings = [],
        bool $allWings = false
    ): string {
        // Step 1: Initiate consent
        $authResp = $this->get('/oauth/authorize?' . http_build_query([
            'client_id'     => $client->id,
            'redirect_uri'  => 'http://localhost/cb',
            'response_type' => 'code',
            'scope'         => 'palace.read',
            'state'         => 'st',
        ]));
        $authResp->assertStatus(200);

        $authToken = $authResp->viewData('authToken')
            ?? app('session.store')->get('authToken')
            ?? null;
        $this->assertNotNull($authToken, 'authToken not found in session or view data');

        // Step 2: Approve consent (stores wing data in cache via CaptureConsentWings middleware)
        $postData = [
            'auth_token' => $authToken,
            'client_id'  => $client->id,
            'scopes'     => ['palace.read'],
        ];
        if ($allWings) {
            $postData['all_wings'] = '1';
        } else {
            $postData['wings'] = $wings;
        }

        $approve = $this->post('/oauth/authorize', $postData);
        $approve->assertRedirect();

        // Extract the authorization code from the redirect URL
        $location = $approve->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $queryParams);
        $code = $queryParams['code'] ?? null;
        $this->assertNotNull($code, 'Authorization code not found in redirect');

        // Step 3: Exchange code for access token (fires AccessTokenCreated → listener runs)
        // Use plainSecret (set during factory creation) since secret is stored hashed.
        $tokenResp = $this->post('/oauth/token', [
            'grant_type'    => 'authorization_code',
            'client_id'     => $client->id,
            'client_secret' => $client->plainSecret,
            'redirect_uri'  => 'http://localhost/cb',
            'code'          => $code,
        ]);
        $tokenResp->assertStatus(200);
        $tokenData = $tokenResp->json();
        $this->assertArrayHasKey('access_token', $tokenData, 'Expected access_token in token response');

        return $tokenData['access_token'];
    }

    public function test_consent_approval_persists_wing_restrictions(): void
    {
        Wing::factory()->create(['slug' => 'work']);
        Wing::factory()->create(['slug' => 'personal']);

        $user   = User::factory()->create();
        $client = Client::factory()->create([
            'redirect_uris' => ['http://localhost/cb'],
        ]);

        $this->actingAs($user);

        $this->completeOAuthFlow($client, $user, wings: ['work']);

        // Verify a restriction row was written for the issued token
        $restriction = McpTokenRestriction::first();
        $this->assertNotNull($restriction, 'Expected McpTokenRestriction to be created');
        $this->assertEquals(['work'], $restriction->wing_patterns);
    }

    public function test_all_wings_checkbox_persists_null_restriction(): void
    {
        $user   = User::factory()->create();
        $client = Client::factory()->create([
            'redirect_uris' => ['http://localhost/cb'],
        ]);

        $this->actingAs($user);

        $this->completeOAuthFlow($client, $user, allWings: true);

        $restriction = McpTokenRestriction::first();
        $this->assertNotNull($restriction, 'Expected McpTokenRestriction to be created');
        $this->assertNull($restriction->wing_patterns);
    }
}
