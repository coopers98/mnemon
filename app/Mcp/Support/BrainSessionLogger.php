<?php

namespace App\Mcp\Support;

use App\Models\BrainSession;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Passport\Client;
use Laravel\Passport\Token;

class BrainSessionLogger
{
    public static function log(Request $request, string $tool, array $input, int $resultCount): void
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $client = $token?->client;

        BrainSession::create([
            'tool_name' => $tool,
            'oauth_client_id' => $client?->id,
            'user_id' => $user?->id,
            'access_token_id' => $token?->id,
            'source' => self::renderSource($client, $user, $token),
            'input' => $input,
            'result_count' => $resultCount,
        ]);
    }

    /**
     * Record a refused invocation.
     *
     * Denials are the invocations an audit trail exists to capture, so they
     * are written before the error Response is returned. Only authenticated
     * callers reach a tool guard — auth:api rejects the rest — so there is no
     * unauthenticated write path into this table.
     */
    public static function logDenial(Request $request, string $tool, array $input, string $reason): void
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        $client = $token?->client;

        BrainSession::create([
            'tool_name' => $tool,
            'oauth_client_id' => $client?->id,
            'user_id' => $user?->id,
            'access_token_id' => $token?->id,
            'source' => self::renderSource($client, $user, $token),
            'input' => $input,
            'result_count' => 0,
            'outcome' => 'denied',
            'error' => $reason,
        ]);
    }

    private static function renderSource(?Client $client, ?User $user, mixed $token): string
    {
        $parts = [];
        // Prefer the token's display name (e.g. "Claude Code"); fall back to client name.
        $parts[] = $token?->name ?? $client?->name ?? 'unknown-client';
        if ($user) {
            $parts[] = "as {$user->email}";
        }
        if ($token) {
            $parts[] = '(token …'.substr($token->id, -4).')';
        }

        return implode(' ', $parts);
    }
}
