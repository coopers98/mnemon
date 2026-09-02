<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

class OauthClientLookupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `oauth_clients.id` is a native uuid column. Passport's repository puts the
     * raw id straight into the query, so a malformed one is a database error on
     * PostgreSQL — and a QueryException is not a League exception, so the OAuth
     * error handler cannot turn it into a 4xx. The result is a 500 on three
     * endpoints.
     *
     * Asserting on the query rather than the return value keeps the test
     * meaningful on SQLite, which is permissive enough to return null and hide
     * the whole problem. That permissiveness is why this shipped.
     */
    public function test_a_malformed_client_id_never_reaches_the_database(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $result = app(ClientRepository::class)->find('not-a-uuid');

        $this->assertNull($result);
        $this->assertSame([], $queries,
            'a malformed client id must be rejected before it reaches a uuid-typed column');
    }

    public function test_authorize_rejects_a_malformed_client_id_without_a_500(): void
    {
        $response = $this->get('/oauth/authorize?'.http_build_query([
            'client_id' => 'not-a-uuid',
            'redirect_uri' => 'http://localhost/cb',
            'response_type' => 'code',
            'scope' => 'mcp:use',
        ]));

        $this->assertLessThan(500, $response->getStatusCode(),
            'a malformed client id is a client error, not a server error (D15)');
    }

    public function test_the_token_endpoint_rejects_a_malformed_client_id_without_a_500(): void
    {
        $response = $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => 'whatever',
            'client_id' => 'not-a-uuid',
            'client_secret' => 'whatever',
        ]);

        $this->assertLessThan(500, $response->getStatusCode(),
            'the refresh grant must answer a bad client with an OAuth error, not a 500');
    }

    public function test_the_device_code_endpoint_rejects_a_malformed_client_id_without_a_500(): void
    {
        $response = $this->postJson('/oauth/device/code', [
            'client_id' => 'not-a-uuid',
            'scope' => 'mcp:use',
        ]);

        $this->assertLessThan(500, $response->getStatusCode(),
            'the device grant is the first thing a new device touches; it must not 500');
    }

    public function test_a_well_formed_client_id_is_still_looked_up(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        app(ClientRepository::class)->find('0199a1f4-1b2c-7000-8000-000000000000');

        $this->assertNotEmpty($queries, 'a valid uuid must still be queried for');
    }
}
