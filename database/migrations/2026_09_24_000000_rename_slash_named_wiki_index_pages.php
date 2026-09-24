<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The two auto-maintained pages were named `wiki/index` and `wiki/log`. The
 * wiki show route constrains the page name to `[a-z0-9:_-]+`, which has no
 * `/`, so both were a permanent 404 on the web UI — on pages the application
 * generates for itself.
 *
 * Widening the route was the other option and is worse: with a `/` allowed in
 * the name, the greedy `wiki.show` pattern swallows the `/history` suffix of
 * the route registered after it, so `/wiki/a/b/history` resolves to a page
 * named `a/b/history` instead of the history view.
 *
 * Renaming to the `type:slug` form every other page already uses
 * (`project:helios`, `person:…`) fixes the 404 without touching routing.
 */
return new class extends Migration
{
    private const RENAMES = [
        'wiki/index' => 'wiki:index',
        'wiki/log' => 'wiki:log',
    ];

    public function up(): void
    {
        $this->rename(self::RENAMES);
    }

    public function down(): void
    {
        $this->rename(array_flip(self::RENAMES));
    }

    /**
     * @param  array<string, string>  $map
     */
    private function rename(array $map): void
    {
        foreach ($map as $from => $to) {
            // `name` is unique. If the target already exists — a re-run, or a
            // page created under the new name before this migration — drop the
            // stale source rather than failing the migration on a constraint.
            // Both pages are regenerated from live data on the next context_set.
            $targetExists = DB::table('wiki_pages')->where('name', $to)->exists();

            if ($targetExists) {
                DB::table('wiki_pages')->where('name', $from)->delete();

                continue;
            }

            DB::table('wiki_pages')->where('name', $from)->update(['name' => $to]);
        }
    }
};
