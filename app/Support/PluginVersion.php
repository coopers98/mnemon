<?php

namespace App\Support;

/**
 * The Claude Code plugin version this instance ships.
 *
 * Devices run a copy of the hooks that Claude Code caches locally and updates
 * only when the version string changes. Nothing told a device it was behind:
 * one machine sat two versions back for days and only manual inspection caught
 * it. That matters more since 0.3.0, because the hooks now carry
 * credential-refresh logic — a stale copy fails in exactly the ways the new one
 * was written to prevent, and fails quietly.
 *
 * `palace_wake_up` reports this so a device can compare it against its own on
 * every session start.
 */
class PluginVersion
{
    private static ?string $cached = null;

    private static bool $resolved = false;

    /**
     * The shipped version, or null when the manifest is not deployed.
     *
     * Null is not an error: an instance may be deployed without the plugin
     * directory. A device that gets no version back simply skips the check
     * rather than warning about a mismatch it cannot judge.
     */
    public static function current(): ?string
    {
        if (self::$resolved) {
            return self::$cached;
        }

        self::$resolved = true;
        self::$cached = null;

        $path = base_path('plugins/mnemon/.claude-plugin/plugin.json');

        if (! is_file($path)) {
            return null;
        }

        $manifest = json_decode((string) file_get_contents($path), true);
        $version = is_array($manifest) ? ($manifest['version'] ?? null) : null;

        if (is_string($version) && $version !== '') {
            self::$cached = $version;
        }

        return self::$cached;
    }

    /**
     * Reset the memoised value. Tests only.
     */
    public static function flush(): void
    {
        self::$resolved = false;
        self::$cached = null;
    }
}
