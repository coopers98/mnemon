<?php

use App\Mcp\Servers\MnemonServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

/*
 * laravel/mcp hardcodes the authorization-server metadata without the device
 * grant, so a server that supports device enrolment advertised itself as one
 * that does not. That is the documentation form of a silent failure: a client
 * reading this document has no way to discover the flow.
 *
 * mnemon-authorize.sh deliberately does not read this document — a setup script
 * that breaks on stale metadata is worse than a derived path on a server we
 * control — so this is about honesty rather than function.
 */
$authorizationServerMetadata = static fn () => response()->json([
    'issuer' => config('mcp.authorization_server') ?? url('/'),
    'authorization_endpoint' => route('passport.authorizations.authorize'),
    'device_authorization_endpoint' => route('passport.device.code'),
    'token_endpoint' => route('passport.token'),
    'registration_endpoint' => url('oauth/register'),
    'response_types_supported' => ['code'],
    'code_challenge_methods_supported' => ['S256'],
    'scopes_supported' => ['mcp:use'],
    'grant_types_supported' => [
        'authorization_code',
        'refresh_token',
        'urn:ietf:params:oauth:grant-type:device_code',
    ],
]);

/*
 * The two overrides sit on opposite sides of Mcp::oauthRoutes(), and the
 * asymmetry is load-bearing.
 *
 * Registrar::oauthRoutes() checks hasGetRoute() before registering the *exact*
 * well-known route, so declaring ours first makes the package skip its own and
 * nothing is shadowed. It registers both *wildcard* routes unconditionally, and
 * Laravel's route collection keys on method and URI — so for an identical
 * pattern the later registration replaces the earlier one. A wildcard declared
 * up here would be silently replaced by the package's, leaving the nested
 * lookup serving stale metadata while the exact one served ours. It has to come
 * after.
 */
Route::get('/.well-known/oauth-authorization-server', $authorizationServerMetadata);

Mcp::oauthRoutes();

// RFC 8414 path-scoped lookup, e.g. issuer https://host with resource /mcp.
Route::get('/.well-known/oauth-authorization-server/{path}', $authorizationServerMetadata)
    ->where('path', '.*');

Mcp::web('/mcp', MnemonServer::class)
    ->middleware(['auth:api', 'throttle:mcp']);
