<?php

namespace Tests\Feature\Mcp;

use Tests\TestCase;

class DiscoveryMetadataTest extends TestCase
{
    public function test_metadata_advertises_the_device_grant(): void
    {
        // laravel/mcp hardcodes this document without the device grant, so a
        // server that supports device enrolment described itself as one that
        // does not — the documentation form of a silent failure.
        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertStatus(200)
            ->assertJsonPath('device_authorization_endpoint', url('/oauth/device/code'))
            ->assertJsonPath('grant_types_supported', [
                'authorization_code',
                'refresh_token',
                'urn:ietf:params:oauth:grant-type:device_code',
            ])
            // The package fields have to survive the override: Claude Code's own
            // DCR flow reads these, and pinning them keeps this copy honest if
            // a future laravel/mcp changes the shape underneath it.
            ->assertJsonPath('registration_endpoint', url('/oauth/register'))
            ->assertJsonPath('token_endpoint', url('/oauth/token'))
            ->assertJsonPath('authorization_endpoint', url('/oauth/authorize'))
            ->assertJsonPath('response_types_supported', ['code'])
            ->assertJsonPath('code_challenge_methods_supported', ['S256'])
            ->assertJsonPath('scopes_supported', ['mcp:use']);
    }

    public function test_the_nested_path_variant_matches(): void
    {
        // RFC 8414 path-scoped lookup: issuer https://host with resource /mcp.
        // laravel/mcp registers this wildcard unconditionally, so overriding only
        // the exact route would leave a second, stale copy of the metadata being
        // served from a URL clients legitimately ask for.
        $this->getJson('/.well-known/oauth-authorization-server/mcp')
            ->assertStatus(200)
            ->assertJsonPath('device_authorization_endpoint', url('/oauth/device/code'))
            ->assertJsonPath('grant_types_supported', [
                'authorization_code',
                'refresh_token',
                'urn:ietf:params:oauth:grant-type:device_code',
            ]);
    }
}
