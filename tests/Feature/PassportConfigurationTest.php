<?php

namespace Tests\Feature;

use Carbon\CarbonInterval;
use Laravel\Passport\Passport;
use Tests\TestCase;

class PassportConfigurationTest extends TestCase
{
    public function test_passport_scopes_are_registered(): void
    {
        $scopes = Passport::scopes()->pluck('id')->all();

        $this->assertContains('palace.read', $scopes);
        $this->assertContains('palace.write', $scopes);
        $this->assertContains('wiki.read', $scopes);
        $this->assertContains('wiki.write', $scopes);
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
