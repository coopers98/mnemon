<?php

namespace App\Support;

/**
 * Wing-pattern matching, in one place.
 *
 * This logic existed twice — once on the restriction models and once in
 * DrawerSearchService — and the wiki isolation fix needed a third caller.
 * Three copies of an authorization predicate is how one of them drifts, so
 * they all delegate here.
 *
 * A pattern is either an exact wing slug or a `*` wildcard, e.g. `work`,
 * `project:*`. Callers are responsible for the `null` case: `null` patterns
 * mean unrestricted, an empty array means deny-all, and that distinction is
 * load-bearing — consent that selected no wings once persisted `null` and
 * therefore granted everything.
 */
final class WingPatterns
{
    /**
     * @param  array<string>  $patterns
     */
    public static function matches(string $wingSlug, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === $wingSlug) {
                return true;
            }

            if (str_contains($pattern, '*')) {
                $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/';
                if (preg_match($regex, $wingSlug)) {
                    return true;
                }
            }
        }

        return false;
    }
}
