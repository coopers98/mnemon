<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate;

/**
 * `auth` that steps aside in demo mode, where the wiki and palace are public.
 *
 * Checked per request, not in routes/web.php: routes are registered once and
 * baked in by route:cache, so a route-file condition could not be changed by
 * config at runtime or in tests.
 */
class AuthenticateUnlessDemo extends Authenticate
{
    public function handle($request, Closure $next, ...$guards)
    {
        if (config('mnemon.demo.enabled')) {
            return $next($request);
        }

        return parent::handle($request, $next, ...$guards);
    }
}
