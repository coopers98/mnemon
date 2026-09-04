<?php

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallScriptTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_install_script_is_served_unauthenticated(): void
    {
        // A device that has never connected has no credentials by definition,
        // so this endpoint cannot require any.
        $this->get('/install')
            ->assertStatus(200)
            ->assertHeader('content-type', 'text/plain; charset=UTF-8');
    }

    public function test_it_embeds_this_instance_url_so_nothing_has_to_be_typed(): void
    {
        config(['app.url' => 'https://mnemon.example.com']);

        $body = $this->get('/install')->getContent();

        $this->assertStringContainsString('https://mnemon.example.com', $body);
        $this->assertStringNotContainsString('<your-instance>', $body,
            'the point of serving this from the instance is that it knows its own address');
    }

    public function test_it_names_the_marketplace_the_instance_is_configured_for(): void
    {
        config(['mnemon.plugin.marketplace' => 'someone-else/mnemon']);

        $this->assertStringContainsString(
            'someone-else/mnemon',
            $this->get('/install')->getContent(),
            'a self-hosted fork must be able to point installs at its own marketplace'
        );
    }

    public function test_the_script_is_valid_shell(): void
    {
        // This is piped straight into bash on someone else's machine. A syntax
        // error would be found by them, halfway through running it.
        $path = tempnam(sys_get_temp_dir(), 'install').'.sh';
        file_put_contents($path, $this->get('/install')->getContent());

        exec('bash -n '.escapeshellarg($path).' 2>&1', $out, $code);
        @unlink($path);

        $this->assertSame(0, $code, 'bash -n rejected the served script: '.implode("\n", $out));
    }

    public function test_it_carries_no_credentials(): void
    {
        // Served to anyone who asks, so it must contain nothing that isn't
        // already public.
        $body = $this->get('/install')->getContent();

        foreach (['client_secret', 'bearer_token', 'APP_KEY', 'password'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }

    public function test_a_malformed_instance_url_cannot_break_out_of_the_quoting(): void
    {
        // Both substituted values land inside single-quoted shell strings, so a
        // quote would end the string and run whatever followed. These come from
        // config rather than from a request, but a script served to every device
        // is the wrong place to rely on that.
        config(['app.url' => "https://evil.example.com'; rm -rf /tmp/x; '"]);

        $body = $this->get('/install')->getContent();

        $this->assertStringNotContainsString('rm -rf', $body);
        $this->assertStringContainsString('<your-instance>', $body,
            'a URL that cannot be quoted safely falls back to the placeholder');
    }

    public function test_it_does_not_enrol_on_the_users_behalf(): void
    {
        // Enrolment needs a client id and secret that only the instance owner
        // can mint, and it replaces a working credential. This script prints
        // the command; it does not run it.
        $body = $this->get('/install')->getContent();

        $this->assertStringContainsString('mnemon-authorize.sh', $body);
        $this->assertMatchesRegularExpression('/mnemon:device-client/', $body,
            'the script must say where the client id comes from');
    }
}
