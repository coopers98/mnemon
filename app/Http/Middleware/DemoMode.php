<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns an install into the public, read-only demo when mnemon.demo.enabled is
 * set (docs/design/2026-10-13-public-demo-design.md §1).
 *
 * Global middleware runs before routing, so this also covers the routes that
 * Filament, Passport, laravel/mcp and Livewire register for themselves. It
 * allows GET and HEAD to a short list of paths and refuses everything else
 * with a bare response: no view is rendered, so a flood of refusals is cheap.
 * An allowlist means a route a package adds later is closed by default.
 */
class DemoMode
{
    public const REFUSED_HEADER = 'X-Mnemon-Demo';

    /** Matched against Request::path(), which is '/' for the root. */
    private const ALLOWED = ['/', 'how-it-works', 'wiki', 'wiki/*', 'palace', 'palace/*', 'up'];

    private const NOT_INDEXED = ['wiki', 'wiki/*', 'palace', 'palace/*'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('mnemon.demo.enabled')) {
            return $next($request);
        }

        // A public demo must never render a stack trace.
        if (config('app.debug')) {
            return $this->refuse(503);
        }

        // Counted before the allowlist, so probing closed paths is limited too.
        $key = 'demo:'.self::clientKey((string) $request->ip());
        if (RateLimiter::tooManyAttempts($key, (int) config('mnemon.demo.rate_limit', 120))) {
            return $this->refuse(429);
        }
        RateLimiter::hit($key, 60);

        $host = config('mnemon.demo.host');
        if ($host && strcasecmp($request->getHost(), $host) !== 0) {
            return $this->refuse(404);
        }

        // The real method: getMethod() honours _method and X-HTTP-METHOD-OVERRIDE.
        if (! in_array($request->getRealMethod(), ['GET', 'HEAD'], true)) {
            return $this->refuse(404);
        }

        if (! $request->is(...self::ALLOWED)) {
            return $this->refuse(404);
        }

        $response = $next($request);

        // A fictional person's notes should not be indexed as if they were real.
        if ($request->is(...self::NOT_INDEXED)) {
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        return $response;
    }

    /**
     * The rate-limit identity of a client: an IPv4 address as-is, an IPv6
     * address by its /64, since one host commonly holds a whole /64. An
     * IPv4-mapped IPv6 address is keyed by the IPv4 address it carries.
     */
    public static function clientKey(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // An IPv4 client seen through a dual-stack socket: key by its own address.
            $packed = inet_pton($ip);
            if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
                return inet_ntop(substr($packed, 12));
            }

            return inet_ntop(substr(inet_pton($ip), 0, 8).str_repeat("\0", 8)).'/64';
        }

        return $ip;
    }

    private function refuse(int $status): Response
    {
        $response = response('', $status)->header(self::REFUSED_HEADER, 'refused');
        if ($status === 429) {
            $response->header('Retry-After', '60');
        }

        return $response;
    }
}
