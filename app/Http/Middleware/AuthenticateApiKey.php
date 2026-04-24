<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $plaintext = $request->header('X-API-Key');

        if (empty($plaintext)) {
            return response()->json([
                'error' => 'Missing API key. Provide X-API-Key header.',
            ], 401);
        }

        $keyHash = hash('sha256', $plaintext);
        $apiKey = ApiKey::where('key_hash', $keyHash)->first();

        if ($apiKey === null) {
            return response()->json([
                'error' => 'Invalid API key.',
            ], 401);
        }

        if ($apiKey->isRevoked()) {
            return response()->json([
                'error' => 'API key has been revoked.',
            ], 401);
        }

        $apiKey->update(['last_used_at' => now()]);

        $request->attributes->set('api_key', $apiKey);

        return $next($request);
    }
}
