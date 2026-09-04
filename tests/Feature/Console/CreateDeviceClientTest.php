<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Tests\TestCase;

class CreateDeviceClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_confidential_device_client_and_prints_enrolment_instructions(): void
    {
        $this->artisan('mnemon:device-client', ['device' => 'thinkpad'])
            ->expectsOutputToContain('claude-code@thinkpad')
            ->expectsOutputToContain('mnemon-authorize.sh')
            ->assertExitCode(0);

        $client = Client::query()->where('name', 'claude-code@thinkpad')->first();
        $this->assertNotNull($client);
        $this->assertTrue($client->confidential());
        $this->assertEqualsCanonicalizing(
            ['urn:ietf:params:oauth:grant-type:device_code', 'refresh_token'],
            $client->grant_types,
        );
    }

    public function test_the_printed_path_resolves_to_the_newest_plugin_copy(): void
    {
        // The plugin cache keeps every installed version side by side, so the
        // lexically first directory is the OLDEST. An earlier version of this
        // hint used `head -1` and pointed at a two-releases-stale script on a
        // real machine. Lexical order is wrong for versions regardless:
        // 0.10.0 sorts before 0.9.0.
        $this->artisan('mnemon:device-client', ['device' => 'thinkpad'])
            ->expectsOutputToContain('sort -V | tail -1')
            ->assertExitCode(0);
    }

    public function test_refuses_a_duplicate_active_device_client(): void
    {
        $this->artisan('mnemon:device-client', ['device' => 'thinkpad'])->assertExitCode(0);

        $this->artisan('mnemon:device-client', ['device' => 'thinkpad'])
            ->expectsOutputToContain('already exists')
            ->assertExitCode(1);
    }
}
