<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class InstallClaudeCodeHooks extends Command
{
    protected $signature = 'mnemon:install-claude-code-hooks
                            {--endpoint= : The MCP endpoint URL}
                            {--token= : Passport bearer token}
                            {--force : Overwrite existing hook scripts}';

    protected $description = 'Install Mnemon Claude Code hooks for automatic capture and recall.';

    /** Hook event => script filename, in the order Claude Code fires them. */
    private const MANAGED_HOOKS = [
        'SessionStart' => 'mnemon-wake.sh',
        'UserPromptSubmit' => 'mnemon-recall.sh',
        'Stop' => 'mnemon-capture.sh',
    ];

    public function handle(): int
    {
        $home = getenv('HOME') ?: $_SERVER['HOME'] ?? null;
        if (! $home) {
            $this->error('Cannot resolve $HOME.');

            return self::FAILURE;
        }

        $claudeDir = $home.'/.claude';
        if (! File::isDirectory($claudeDir)) {
            $this->error("$claudeDir not found. Install Claude Code first: https://docs.claude.com/claude-code");

            return self::FAILURE;
        }

        $endpoint = $this->option('endpoint')
            ?: env('MNEMON_URL')
            ?: $this->ask('Mnemon MCP endpoint URL', 'http://localhost:8080/mcp');

        $token = $this->option('token')
            ?: $this->resolveTokenFromClaudeJson($home)
            ?: $this->secret('Passport bearer token');

        if (empty($endpoint) || empty($token)) {
            $this->error('Endpoint and token are required.');

            return self::FAILURE;
        }

        if (! $this->verifyToken($endpoint, $token)) {
            $this->error('Token verification failed against '.$endpoint);

            return self::FAILURE;
        }

        $mnemonDir = $home.'/.mnemon';
        File::ensureDirectoryExists($mnemonDir);
        File::ensureDirectoryExists($mnemonDir.'/sessions');
        File::put($mnemonDir.'/config.json', json_encode([
            'endpoint' => $endpoint,
            'bearer_token' => $token,
        ], JSON_PRETTY_PRINT));
        chmod($mnemonDir.'/config.json', 0600);

        $hooksDir = $claudeDir.'/hooks';
        File::ensureDirectoryExists($hooksDir.'/lib');

        $repoHooks = base_path('resources/hooks/claude-code');
        foreach (['lib/common.sh', 'mnemon-wake.sh', 'mnemon-recall.sh', 'mnemon-capture.sh'] as $file) {
            $src = "$repoHooks/$file";
            $dst = "$hooksDir/$file";
            if (! File::exists($src)) {
                $this->error("Missing hook script in repo: $src");

                return self::FAILURE;
            }
            if (File::exists($dst) && ! $this->option('force')) {
                if (! $this->confirm("Overwrite $dst?", true)) {
                    continue;
                }
            }
            File::copy($src, $dst);
            chmod($dst, 0755);
        }

        $this->registerHooksInSettings($claudeDir.'/settings.json', $hooksDir);

        $this->info('Mnemon Claude Code hooks installed.');
        $this->info("  Config: $mnemonDir/config.json");
        $this->info("  Hooks:  $hooksDir/{mnemon-wake,mnemon-recall,mnemon-capture}.sh");
        $this->info('Test with: claude → ask anything.');

        return self::SUCCESS;
    }

    private function resolveTokenFromClaudeJson(string $home): ?string
    {
        $f = $home.'/.claude.json';
        if (! File::exists($f)) {
            return null;
        }
        $j = json_decode(File::get($f), true) ?? [];
        $auth = $j['mcpServers']['mnemon']['headers']['Authorization'] ?? null;
        if ($auth && str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }

        return null;
    }

    private function verifyToken(string $endpoint, string $token): bool
    {
        try {
            $r = Http::withToken($token)
                ->timeout(5)
                ->post($endpoint, [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'tools/list',
                    'params' => new \stdClass,
                ]);

            return $r->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Register the three hooks in Claude Code's settings.
     *
     * Claude Code's schema keys `hooks` by event name; each value is a list of
     * groups carrying a `hooks` array of {type, command}. A flat list is
     * silently ignored, so anything written in that shape never runs.
     */
    private function registerHooksInSettings(string $settingsFile, string $hooksDir): void
    {
        $settings = File::exists($settingsFile)
            ? (json_decode(File::get($settingsFile), true) ?: [])
            : [];

        $hooks = $settings['hooks'] ?? [];

        // Earlier versions wrote a flat list of {_mnemon_managed, event, command}.
        // Event names cannot be recovered from it, so it is replaced wholesale.
        if (array_is_list($hooks)) {
            $hooks = [];
        }

        foreach (self::MANAGED_HOOKS as $event => $script) {
            $groups = $hooks[$event] ?? [];

            // Strip only the hooks this command owns. Everything else on this
            // event belongs to the user and must survive untouched.
            $groups = array_map(function (array $group) {
                $group['hooks'] = array_values(array_filter(
                    $group['hooks'] ?? [],
                    fn ($hook) => ! self::ownsHook($hook['command'] ?? '')
                ));

                return $group;
            }, $groups);

            $groups = array_values(array_filter($groups, fn ($group) => $group['hooks'] !== []));

            $groups[] = ['hooks' => [['type' => 'command', 'command' => "$hooksDir/$script"]]];

            $hooks[$event] = $groups;
        }

        $settings['hooks'] = $hooks;

        File::put($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Whether a hook command is one this installer manages.
     */
    private static function ownsHook(string $command): bool
    {
        foreach (self::MANAGED_HOOKS as $script) {
            if (str_contains($command, $script)) {
                return true;
            }
        }

        return false;
    }
}
