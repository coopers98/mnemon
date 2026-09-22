<?php

namespace App\Services\Digest;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiDigestDriver
{
    public function digest(string $transcript, array $context): array
    {
        $apiKey = config('services.openai.api_key') ?? env('OPENAI_API_KEY');
        if (empty($apiKey)) {
            Log::warning('mnemon: OPENAI_API_KEY missing for session_digest');

            return [];
        }

        $model = config('mnemon.digest.openai_model', 'gpt-4o-mini');

        $system = "You are Mnemon's session digester. Read a Claude Code transcript and extract drawers worth storing. ".
            "Respond with strict JSON: {\"proposals\":[{\"content\":\"…\",\"wing_slug\":\"…\",\"room_slug\":\"…\",\"confidence\":0.0-1.0,\"propose_new_wing\":bool,\"propose_new_room\":bool,\"rationale\":\"…\"}]}.\n".
            'Existing wings: '.json_encode($context['existing_wings'] ?? [])."\n".
            'Existing rooms per wing (wing_id keys): '.json_encode($context['existing_rooms_per_wing'] ?? [])."\n".
            'Drawers already captured this session (avoid duplicates): '.json_encode($context['recent_drawers'] ?? []);

        // A timeout does not come back as an unsuccessful response -- the HTTP
        // client throws, so it would sail past the `successful()` check below,
        // escape the tool, and reach the caller as a JSON-RPC 500 reading
        // "Something went wrong while processing the request". Two live digests
        // were lost that way to `cURL error 28` against api.openai.com.
        //
        // An upstream timeout is not a server fault. Degrade exactly as an
        // error status does: no proposals, a log line, and a digest the next
        // Stop retries. 20s was the old budget and proved too tight for a large
        // transcript; it is configurable now so it can be tuned without a patch.
        try {
            $response = Http::withToken($apiKey)
                ->timeout((int) config('mnemon.digest.timeout', 60))
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => "Transcript:\n\n".$transcript],
                    ],
                ]);
        } catch (ConnectionException $e) {
            Log::warning('mnemon: openai digest call could not complete', [
                'reason' => $e->getMessage(),
            ]);

            return [];
        }

        if (! $response->successful()) {
            Log::warning('mnemon: openai digest call failed', ['status' => $response->status()]);

            return [];
        }

        $content = $response->json('choices.0.message.content');
        $parsed = json_decode($content ?? '', true);

        if (! is_array($parsed) || ! isset($parsed['proposals']) || ! is_array($parsed['proposals'])) {
            return [];
        }

        return $parsed['proposals'];
    }
}
