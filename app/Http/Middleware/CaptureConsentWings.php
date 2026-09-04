<?php

namespace App\Http\Middleware;

use App\Models\McpClientRestriction;
use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\Bridge\Client as BridgeClient;
use Laravel\Passport\Bridge\DeviceCode;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Client;
use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Persists the wings chosen on the consent screen, keyed on the client.
 *
 * Restrictions belong to the client rather than to an hour-long access token:
 * keying them on the token meant a refreshed token had no row, and a missing row
 * means unrestricted.
 *
 * Both consent screens funnel through here: the auth-code approve carries its
 * client in the form, the device approve takes it from the session's device
 * code.
 *
 * Three things must hold before anything is written. The request has to be one
 * of the two approve routes, by name — deny shares a URI with approve and
 * redirects to the same place with no error to tell them apart. It has to be a
 * genuine submission of the form we rendered, since this middleware runs
 * *before* Passport validates the approval, and without that check any
 * authenticated POST could rewrite a client's wings, widening them included. And
 * the approval has to have actually succeeded, so a refusal leaves the previous
 * restriction intact.
 *
 * The design this replaced got the last property by accident: it stashed the
 * selection in the cache, which was only consumed if a token was issued. Writing
 * synchronously is better for visibility — a failure surfaces to the person who
 * clicked approve — but it has to earn that property back rather than inherit
 * it.
 */
class CaptureConsentWings
{
    /**
     * The two approve routes, matched by name rather than by URI and method.
     *
     * Deny shares a URI with approve and is separated only by a spoofed DELETE,
     * so a URI match had to lean on isMethod('POST') to tell them apart — and a
     * denial redirects to the same place an approval does, with no error in the
     * query, so nothing downstream would have caught it. Route names distinguish
     * the two outright.
     */
    private const APPROVE_ROUTES = [
        'passport.authorizations.approve',
        'passport.device.authorizations.approve',
    ];

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
        if (! $request->user() || ! in_array($request->route()?->getName(), self::APPROVE_ROUTES, true)) {
            return null;
        }

        // Proves this is the consent form we rendered, not a crafted POST.
        $expected = (string) $request->session()->get('authToken', '');
        $given = (string) $request->input('auth_token', '');

        if ($expected === '' || ! hash_equals($expected, $given)) {
            return null;
        }

        // Any device route resolves its client from the session, not from input.
        $clientId = str_starts_with((string) $request->route()?->getName(), 'passport.device.')
            ? $this->deviceClientId($request)
            : (string) ($request->input('client_id') ?? '');

        if ($clientId === '' || Client::find($clientId) === null) {
            return null;
        }

        return $clientId;
    }

    /**
     * The client behind a device approval.
     *
     * The device approve form carries no client_id — deliberately, since a field
     * the browser controls would be a second and forgeable source of truth. The
     * authoritative value is the device code the authorize screen serialized into
     * the session, which is the same thing Passport's approve controller reads.
     *
     * Read it without consuming it: the controller pull()s both the device code
     * and the auth token after this middleware has run.
     *
     * A tampered session yields null and no row is written — and the approve
     * controller then fails on that same session value, so an approval cannot
     * slip through unrestricted through this gap.
     */
    private function deviceClientId(Request $request): string
    {
        $serialized = $request->session()->get('deviceCode');

        if (! is_string($serialized) || $serialized === '') {
            return '';
        }

        $deviceCode = unserialize($serialized, ['allowed_classes' => [
            DeviceCode::class,
            BridgeClient::class,
            Scope::class,
            \DateTimeImmutable::class,
        ]]);

        return $deviceCode instanceof DeviceCodeEntityInterface
            ? (string) $deviceCode->getClient()->getIdentifier()
            : '';
    }

    /**
     * Whether Passport accepted the approval.
     *
     * A successful auth-code approval redirects back to the client with a code;
     * a device approval redirects to /oauth/device with an approved status. A
     * refusal redirects with an error, or does not redirect at all.
     *
     * Denials never reach here: they are a different route, rejected by name
     * above. That matters, because a device denial redirects to exactly where an
     * approval does and carries no error to distinguish it.
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
