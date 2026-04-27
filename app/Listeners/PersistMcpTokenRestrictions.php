<?php

namespace App\Listeners;

use App\Models\McpTokenRestriction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Passport\Events\AccessTokenCreated;

class PersistMcpTokenRestrictions
{
    public function __construct(protected Request $request) {}

    /**
     * Cache key for pending wing restrictions set during consent approval.
     * Keyed by userId + clientId so the AccessTokenCreated listener can read it.
     */
    public static function pendingCacheKey(string $userId, string $clientId): string
    {
        return "mcp_consent_wings:{$userId}:{$clientId}";
    }

    /**
     * Store wing restriction data from the consent form in cache so the
     * AccessTokenCreated listener can retrieve it during token exchange.
     * Call this from the consent approval request context.
     */
    public static function storeConsentData(Request $request, string $userId, string $clientId): void
    {
        $allWings = $request->boolean('all_wings');
        $wings = $request->input('wings', []);
        $patterns = $allWings ? null : (empty($wings) ? null : array_values((array) $wings));

        Cache::put(
            static::pendingCacheKey($userId, $clientId),
            ['patterns' => $patterns, 'set' => true],
            now()->addMinutes(10)   // short TTL; token exchange should happen immediately
        );
    }

    public function handle(AccessTokenCreated $event): void
    {
        // Try to read wing restrictions stored during consent approval.
        $cacheKey = static::pendingCacheKey((string) $event->userId, $event->clientId);
        $pending = Cache::pull($cacheKey);   // pull = get + delete

        if ($pending === null) {
            // No consent data found — this is a personal access token,
            // refresh token grant, or some other non-consent flow. Skip.
            return;
        }

        McpTokenRestriction::updateOrCreate(
            ['access_token_id' => $event->tokenId],
            ['wing_patterns' => $pending['patterns']]
        );
    }
}
