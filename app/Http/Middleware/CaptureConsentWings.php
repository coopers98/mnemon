<?php

namespace App\Http\Middleware;

use App\Models\McpClientRestriction;
use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\Client;
use Symfony\Component\HttpFoundation\Response;

/**
 * Persists the wings chosen on the consent screen, keyed on the client.
 *
 * Restrictions belong to the client rather than to an hour-long access token:
 * keying them on the token meant a refreshed token had no row, and a missing row
 * means unrestricted.
 *
 * Two things must hold before anything is written. The request has to be a
 * genuine submission of the consent form — the middleware runs *before* Passport
 * validates the approval, so without this check any authenticated POST naming a
 * client_id could rewrite that client's wings, including widening them. And the
 * approval has to have actually succeeded, so a rejected consent leaves the
 * previous restriction intact.
 *
 * The design this replaced got the second property by accident: it stashed the
 * selection in the cache, which was only consumed if a token was issued. Writing
 * synchronously is better for visibility but has to earn that property back.
 */
class CaptureConsentWings
{
    public function handle(Request $request, Closure $next): Response
    {
        $clientId = $this->approvedClientId($request);

        $response = $next($request);

        if ($clientId !== null && $this->approvalSucceeded($response)) {
            McpClientRestriction::updateOrCreate(
                ['client_id' => $clientId],
                ['wing_patterns' => static::consentPatterns($request), 'created_at' => now()],
            );
        }

        return $response;
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

    /**
     * The client this request may write a restriction for, or null.
     */
    private function approvedClientId(Request $request): ?string
    {
        if (! $request->isMethod('POST') || ! $request->user()) {
            return null;
        }

        if (! $request->is('oauth/authorize') && ! $request->is('oauth/device/authorize')) {
            return null;
        }

        // Proves this is the consent form we rendered, not a crafted POST.
        $expected = (string) $request->session()->get('authToken', '');
        $given = (string) $request->input('auth_token', '');

        if ($expected === '' || ! hash_equals($expected, $given)) {
            return null;
        }

        $clientId = (string) ($request->input('client_id') ?? '');

        if ($clientId === '' || Client::find($clientId) === null) {
            return null;
        }

        return $clientId;
    }

    /**
     * Whether Passport accepted the approval.
     *
     * A successful approval redirects back to the client with a code; a refusal
     * redirects with an error or does not redirect at all.
     */
    private function approvalSucceeded(Response $response): bool
    {
        if (! $response->isRedirection()) {
            return false;
        }

        $location = (string) $response->headers->get('Location', '');

        return $location !== '' && ! str_contains($location, 'error=');
    }
}
