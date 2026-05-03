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
        $hooks = $settings['hooks'] ?? [];
        $entries = array_filter($hooks, fn ($h) => str_contains($h['command'] ?? '', 'mnemon-recall.sh'));
        $this->assertCount(1, $entries);
    }
}
