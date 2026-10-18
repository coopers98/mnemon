<?php

namespace App\Services\Digest;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiDigestDriver
{
    /**
     * @return array<int, array<string, mixed>> The model's proposals; empty when it found nothing worth keeping.
     *
     * @throws DigestUnavailable When the model could not be asked or its answer could not be read.
     */
    public function digest(string $transcript, array $context): array
    {
        $apiKey = config('services.openai.api_key') ?? env('OPENAI_API_KEY');
        if (empty($apiKey)) {
            Log::warning('mnemon: OPENAI_API_KEY missing for session_digest');

            throw new DigestUnavailable('OPENAI_API_KEY is not set on the server', retryable: false);
        }

        $model = config('mnemon.digest.openai_model') ?: config('mnemon.llm.model', 'gpt-5.6-luna');

        // The session's project is known from where it ran; say so, so the
        // model writes for that project. The service enforces the filing.
        $sessionWing = isset($context['session_wing'])
            ? "\nThis session ran in the project whose wing is '{$context['session_wing']}'. File project-specific drawers there; ".
              "use another wing only for content that is not about this project (a person, a cross-project decision).\n"
            : '';

        $system = "You are Mnemon's session digester. Read a Claude Code transcript and extract drawers worth storing. ".
            "Respond with strict JSON: {\"proposals\":[{\"content\":\"…\",\"wing_slug\":\"…\",\"room_slug\":\"…\",\"confidence\":0.0-1.0,\"propose_new_wing\":bool,\"propose_new_room\":bool,\"rationale\":\"…\"}]}.\n".
            'Existing wings: '.json_encode($context['existing_wings'] ?? [])."\n".
            'Existing rooms per wing (wing_id keys): '.json_encode($context['existing_rooms_per_wing'] ?? [])."\n".
            'Drawers already captured this session (avoid duplicates): '.json_encode($context['recent_drawers'] ?? []).
            $sessionWing;

        // A timeout does not come back as an unsuccessful response -- the HTTP
        // client throws. Either way the model was never heard from, which is
        // not the same as the model finding nothing: returning [] for both had
        // the slice recorded as digested and its turns lost. 20s was the old
        // budget and proved too tight for a large transcript; it is
        // configurable so it can be tuned without a patch.
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

            // The transport's own message (a cURL error and its timings) goes
            // to the log, not to the client.
            throw new DigestUnavailable('the model call timed out or could not connect', retryable: true);
        }

        if (! $response->successful()) {
            $status = $response->status();
            Log::warning('mnemon: openai digest call failed', ['status' => $status]);

            // 429 and 5xx clear on their own; any other 4xx (a bad key, a
            // rejected request) fails the same way on every retry.
            throw new DigestUnavailable(
                "the model call failed with HTTP {$status}",
                retryable: $status === 429 || $status >= 500,
            );
        }

        $content = $response->json('choices.0.message.content');
        $parsed = json_decode($content ?? '', true);

        if (! is_array($parsed) || ! isset($parsed['proposals']) || ! is_array($parsed['proposals'])) {
            Log::warning('mnemon: openai digest returned unparseable content');

            throw new DigestUnavailable('the model answered with content that is not a proposals list', retryable: true);
        }

        // Only what the service can persist. One proposal it cannot -- no
        // content, content that is not text -- used to throw mid-loop after
        // earlier drawers were written, and the client's retry wrote them again.
        return array_values(array_filter(
            $parsed['proposals'],
            fn ($p) => is_array($p)
                && is_string($p['content'] ?? null) && trim($p['content']) !== ''
                && (is_int($p['confidence'] ?? null) || is_float($p['confidence'] ?? null)),
        ));
    }
}
