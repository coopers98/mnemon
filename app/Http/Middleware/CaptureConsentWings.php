<?php

namespace App\Http\Middleware;

use App\Models\McpClientRestriction;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Persists the wings chosen on the consent screen, keyed on the client.
 *
 * This used to stash the selection in the cache for an `AccessTokenCreated`
 * listener to pick up at token exchange. That handoff was the bug: a refresh
 * mints a new token with no cached consent, the listener skipped, no row was
 * written, and a missing row means unrestricted. Restrictions now belong to the
 * client and are written here, synchronously, in the request that captured the
 * consent — so a failure surfaces to the person who just clicked approve.
 */
class CaptureConsentWings
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') && $request->user() && $this->isConsentApproval($request)) {
            $clientId = $this->clientIdFor($request);

            if ($clientId !== '') {
                McpClientRestriction::updateOrCreate(
                    ['client_id' => $clientId],
                    ['wing_patterns' => static::consentPatterns($request), 'created_at' => now()],
                );
            }
        }

        return $next($request);
    }

    /**
     * The wings consented to.
     *
     * `null` means all wings, `[]` means none. An empty selection used to
     * collapse to `null` and therefore granted everything — the strictest input
     * the screen offers producing its loosest outcome.
     *
     * @return array<int, string>|null
     */
    public static function consentPatterns(Request $request): ?array
    {
        if ($request->boolean('all_wings')) {
            return null;
        }

        return array_values(array_filter((array) $request->input('wings', []), 'is_string'));
    }

    private function isConsentApproval(Request $request): bool
    {
        return $request->is('oauth/authorize') || $request->is('oauth/device/authorize');
    }

    private function clientIdFor(Request $request): string
    {
        return (string) ($request->input('client_id') ?? '');
    }
}
