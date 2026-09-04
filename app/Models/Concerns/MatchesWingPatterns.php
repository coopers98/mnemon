<?php

namespace App\Models\Concerns;

/**
 * Wing-pattern matching, shared by the client- and token-keyed restrictions.
 *
 * `null` patterns mean unrestricted; an empty array means deny-all. The
 * distinction matters: consent that selected no wings used to persist `null`
 * and therefore granted everything.
 */
trait MatchesWingPatterns
{
    public function matches(string $wingSlug): bool
    {
        $patterns = $this->wing_patterns;

        if ($patterns === null) {
            return true;
        }

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
