<?php

namespace Tests\Feature;

use Tests\TestCase;

class PluginManifestTest extends TestCase
{
    /**
     * The version is the update signal: `claude plugin update` re-fetches only
     * when it changes, so a hook fix shipped without a bump never reaches an
     * installed plugin. It reported "already at the latest version" and served
     * stale code once already.
     */
    public function test_plugin_and_marketplace_versions_agree(): void
    {
        $plugin = json_decode(file_get_contents(base_path('plugins/mnemon/.claude-plugin/plugin.json')), true);
        $marketplace = json_decode(file_get_contents(base_path('.claude-plugin/marketplace.json')), true);

        $entry = collect($marketplace['plugins'])->firstWhere('name', 'mnemon');

        $this->assertNotNull($entry, 'the marketplace must list the mnemon plugin');
        $this->assertSame($plugin['version'], $entry['version'],
            'plugin.json and the marketplace entry must declare the same version');
    }

    public function test_every_declared_hook_script_exists(): void
    {
        $hooks = json_decode(file_get_contents(base_path('plugins/mnemon/hooks/hooks.json')), true);

        $commands = collect($hooks['hooks'])
            ->flatMap(fn ($groups) => collect($groups)->flatMap(fn ($g) => $g['hooks']))
            ->pluck('command');

        $this->assertCount(3, $commands, 'three lifecycle hooks are expected');

        foreach ($commands as $command) {
            preg_match('#\$\{CLAUDE_PLUGIN_ROOT\}/(\S+?)"#', $command, $m);
            $this->assertNotEmpty($m, "hook command must reference \${CLAUDE_PLUGIN_ROOT}: $command");
            $this->assertFileExists(base_path('plugins/mnemon/'.$m[1]),
                "hook script declared in hooks.json is missing: {$m[1]}");
        }
    }
}
