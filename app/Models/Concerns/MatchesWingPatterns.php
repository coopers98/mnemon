<?php

namespace App\Models\Concerns;

use App\Support\WingPatterns;

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

        return WingPatterns::matches($wingSlug, $patterns);
    }
}
