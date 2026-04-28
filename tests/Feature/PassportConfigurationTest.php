<?php

namespace Tests\Feature;

use Carbon\CarbonInterval;
use Laravel\Passport\Passport;
use Tests\TestCase;

class PassportConfigurationTest extends TestCase
{
    public function test_mcp_use_scope_is_registered(): void
    {
        // The laravel/mcp package auto-injects mcp:use via Registrar::ensureMcpScope().
        // Boot the MCP server to trigger registration, then confirm the scope is present.
        $scopes = Passport::scopes()->pluck('id')->all();

        $this->assertContains('mcp:use', $scopes);
        $this->assertNotContains('palace.read', $scopes);
        $this->assertNotContains('palace.write', $scopes);
        $this->assertNotContains('wiki.read', $scopes);
        $this->assertNotContains('wiki.write', $scopes);
    }

    public function test_token_lifetimes_are_set(): void
    {
        // Passport returns a plain DateInterval; wrap in CarbonInterval for totalSeconds
        $tokenSeconds = CarbonInterval::instance(Passport::tokensExpireIn())->totalSeconds;
        $refreshSeconds = CarbonInterval::instance(Passport::refreshTokensExpireIn())->totalSeconds;

        $this->assertEqualsWithDelta(3600, $tokenSeconds, 1);
        $this->assertEqualsWithDelta(90 * 24 * 3600, $refreshSeconds, 1);
    }
}
