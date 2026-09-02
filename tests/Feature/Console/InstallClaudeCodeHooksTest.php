<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InstallClaudeCodeHooksTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir().'/mnemon-test-'.uniqid();
        File::ensureDirectoryExists($this->home.'/.claude');
        File::put($this->home.'/.claude/settings.json', '{}');
        putenv('HOME='.$this->home);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->home);
        parent::tearDown();
    }

    public function test_command_writes_config_and_hook_scripts(): void
    {
        Http::fake([
            'localhost/mcp' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => []]]),
        ]);

        $this->artisan('mnemon:install-claude-code-hooks', [
            '--endpoint' => 'http://localhost/mcp',
            '--token' => 'fake-token',
        ])->assertExitCode(0);

        $this->assertFileExists($this->home.'/.mnemon/config.json');
        $config = json_decode(File::get($this->home.'/.mnemon/config.json'), true);
        $this->assertEquals('http://localhost/mcp', $config['endpoint']);
        $this->assertEquals('fake-token', $config['bearer_token']);

        $this->assertFileExists($this->home.'/.claude/hooks/mnemon-wake.sh');
        $this->assertFileExists($this->home.'/.claude/hooks/mnemon-recall.sh');
        $this->assertFileExists($this->home.'/.claude/hooks/mnemon-capture.sh');
        $this->assertFileExists($this->home.'/.claude/hooks/lib/common.sh');
    }

    public function test_command_aborts_when_claude_dir_missing(): void
    {
        File::deleteDirectory($this->home.'/.claude');

        $this->artisan('mnemon:install-claude-code-hooks', [
            '--endpoint' => 'http://localhost/mcp',
            '--token' => 'fake-token',
        ])->assertFailed();
    }

    public function test_rerun_does_not_duplicate_hook_entries(): void
    {
        Http::fake(['localhost/mcp' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => []])]);

        $this->artisan('mnemon:install-claude-code-hooks',
            ['--endpoint' => 'http://localhost/mcp', '--token' => 'tok', '--force' => true])->assertExitCode(0);
        $this->artisan('mnemon:install-claude-code-hooks',
            ['--endpoint' => 'http://localhost/mcp', '--token' => 'tok', '--force' => true])->assertExitCode(0);

        $settings = json_decode(File::get($this->home.'/.claude/settings.json'), true);
        $commands = $this->commandsFor($settings, 'UserPromptSubmit');
        $this->assertCount(1, array_filter($commands, fn ($c) => str_contains($c, 'mnemon-recall.sh')));
    }

    /**
     * Every command string registered under one hook event.
     *
     * @return array<int, string>
     */
    private function commandsFor(array $settings, string $event): array
    {
        $out = [];
        foreach ($settings['hooks'][$event] ?? [] as $group) {
            foreach ($group['hooks'] ?? [] as $hook) {
                $out[] = $hook['command'] ?? '';
            }
        }

        return $out;
    }

    public function test_rerun_preserves_settings_the_user_added_to_config(): void
    {
        Http::fake(['localhost/mcp' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => []])]);

        File::ensureDirectoryExists($this->home.'/.mnemon');
        File::put($this->home.'/.mnemon/config.json', json_encode([
            'endpoint' => 'http://old/mcp',
            'bearer_token' => 'old',
            'recall_timeout_ms' => 3000,
        ]));

        $this->artisan('mnemon:install-claude-code-hooks',
            ['--endpoint' => 'http://localhost/mcp', '--token' => 'tok'])->assertExitCode(0);

        $config = json_decode(File::get($this->home.'/.mnemon/config.json'), true);

        $this->assertSame('http://localhost/mcp', $config['endpoint'], 'endpoint should be updated');
        $this->assertSame('tok', $config['bearer_token'], 'token should be updated');
        $this->assertSame(3000, $config['recall_timeout_ms'] ?? null,
            'a tuned budget must survive a reinstall, or recall silently reverts');
    }

    public function test_hooks_are_registered_in_claude_code_schema(): void
    {
        Http::fake(['localhost/mcp' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => []])]);

        $this->artisan('mnemon:install-claude-code-hooks',
            ['--endpoint' => 'http://localhost/mcp', '--token' => 'tok'])->assertExitCode(0);

        $settings = json_decode(File::get($this->home.'/.claude/settings.json'), true);

        // Claude Code's schema: hooks is an OBJECT keyed by event name, whose
        // values are arrays of groups, each carrying a `hooks` list of
        // {type: command, command: ...}. A flat list is silently ignored.
        $this->assertIsArray($settings['hooks']);
        $this->assertArrayNotHasKey(0, $settings['hooks'], 'hooks must be keyed by event, not a flat list');

        foreach ([
            'SessionStart' => 'mnemon-wake.sh',
            'UserPromptSubmit' => 'mnemon-recall.sh',
            'Stop' => 'mnemon-capture.sh',
        ] as $event => $script) {
            $this->assertArrayHasKey($event, $settings['hooks']);
            $commands = $this->commandsFor($settings, $event);
            $this->assertCount(1, array_filter($commands, fn ($c) => str_contains($c, $script)),
                "expected exactly one $script under $event");

            $type = $settings['hooks'][$event][0]['hooks'][0]['type'] ?? null;
            $this->assertSame('command', $type, "hook under $event must declare type=command");
        }
    }

    public function test_preserves_hooks_it_does_not_own(): void
    {
        Http::fake(['localhost/mcp' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => []])]);

        // A user with existing hooks — one under an event we touch, one under an
        // event we never touch. Both must survive.
        File::put($this->home.'/.claude/settings.json', json_encode(['hooks' => [
            'SessionStart' => [['hooks' => [['type' => 'command', 'command' => '/usr/local/bin/my-greeter.sh']]]],
            'PostToolUse' => [['matcher' => 'Write|Edit', 'hooks' => [['type' => 'command', 'command' => 'prettier --write']]]],
        ]]));

        $this->artisan('mnemon:install-claude-code-hooks',
            ['--endpoint' => 'http://localhost/mcp', '--token' => 'tok'])->assertExitCode(0);

        $settings = json_decode(File::get($this->home.'/.claude/settings.json'), true);

        $this->assertContains('/usr/local/bin/my-greeter.sh', $this->commandsFor($settings, 'SessionStart'),
            'a pre-existing SessionStart hook must not be destroyed');
        $this->assertContains('prettier --write', $this->commandsFor($settings, 'PostToolUse'),
            'an untouched event must not be destroyed');
        $this->assertSame('Write|Edit', $settings['hooks']['PostToolUse'][0]['matcher'] ?? null,
            'a foreign matcher must be preserved verbatim');
    }

    public function test_migrates_the_legacy_flat_hook_list(): void
    {
        Http::fake(['localhost/mcp' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => []])]);

        // What earlier versions of this command wrote. Claude Code ignores it.
        File::put($this->home.'/.claude/settings.json', json_encode(['hooks' => [
            ['_mnemon_managed' => true, 'event' => 'SessionStart', 'command' => '/old/path/mnemon-wake.sh'],
            ['_mnemon_managed' => true, 'event' => 'Stop', 'command' => '/old/path/mnemon-capture.sh'],
        ]]));

        $this->artisan('mnemon:install-claude-code-hooks',
            ['--endpoint' => 'http://localhost/mcp', '--token' => 'tok'])->assertExitCode(0);

        $settings = json_decode(File::get($this->home.'/.claude/settings.json'), true);

        $this->assertArrayNotHasKey(0, $settings['hooks'], 'legacy flat entries must not survive');
        $this->assertCount(1, $this->commandsFor($settings, 'SessionStart'));
        $this->assertStringContainsString('mnemon-wake.sh', $this->commandsFor($settings, 'SessionStart')[0]);
    }
}
