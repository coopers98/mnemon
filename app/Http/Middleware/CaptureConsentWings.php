<?php

namespace App\Http\Middleware;

use App\Listeners\PersistMcpTokenRestrictions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Captures wing restriction data from the OAuth consent approval form
 * and stores it in cache so PersistMcpTokenRestrictions can later
 * apply it when the access token is issued.
 *
 * This middleware runs BEFORE the approve controller so that when
 * AccessTokenCreated fires (during token exchange, not during consent),
 * the cached restriction data is available.
 */
class CaptureConsentWings
{
    public function handle(Request $request, Closure $next): Response
    {
        // Only act on the OAuth consent approval POST at oauth/authorize.
        if ($request->isMethod('POST') &&
            $request->is('oauth/authorize') &&
            $request->user()
        ) {
            $userId = (string) $request->user()->getKey();
            $clientId = (string) ($request->input('client_id') ?? '');

            if ($clientId !== '') {
                PersistMcpTokenRestrictions::storeConsentData($request, $userId, $clientId);
            }
        }

        return $next($request);
    }
}
