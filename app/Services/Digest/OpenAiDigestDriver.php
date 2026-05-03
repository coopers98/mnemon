<?php

namespace App\Services\Digest;

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
            "Existing wings: ".json_encode($context['existing_wings'] ?? [])."\n".
            "Existing rooms per wing (wing_id keys): ".json_encode($context['existing_rooms_per_wing'] ?? [])."\n".
            "Drawers already captured this session (avoid duplicates): ".json_encode($context['recent_drawers'] ?? []);

        $response = Http::withToken($apiKey)
            ->timeout(20)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => "Transcript:\n\n".$transcript],
                ],
            ]);

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
