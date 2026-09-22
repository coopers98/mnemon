<?php

namespace Tests\Unit\Services\Digest;

use App\Services\Digest\OpenAiDigestDriver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiDigestDriverTest extends TestCase
{
    public function test_parses_proposals_from_openai_response(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'proposals' => [[
                                'content' => 'Meeting with Atlas team',
                                'wing_slug' => 'work',
                                'room_slug' => 'meetings',
                                'confidence' => 0.85,
                                'propose_new_wing' => false,
                                'propose_new_room' => false,
                                'rationale' => 'Clear meeting note',
                            ]],
                        ]),
                    ],
                ]],
            ]),
        ]);

        config(['mnemon.digest.openai_model' => 'gpt-4o-mini']);
        config(['services.openai.api_key' => 'sk-test']);

        $driver = new OpenAiDigestDriver;
        $result = $driver->digest('test transcript', [
            'existing_wings' => ['work'],
            'existing_rooms_per_wing' => [],
            'recent_drawers' => [],
        ]);

        $this->assertCount(1, $result);
        $this->assertEquals('Meeting with Atlas team', $result[0]['content']);
    }

    public function test_returns_empty_array_on_malformed_response(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'not json']]],
            ]),
        ]);
        config(['services.openai.api_key' => 'sk-test']);

        $driver = new OpenAiDigestDriver;
        $result = $driver->digest('t', []);
        $this->assertEquals([], $result);
    }

    /**
     * A timeout does not come back as an unsuccessful response -- Laravel's
     * HTTP client *throws* ConnectionException, so it sails straight past the
     * `! $response->successful()` guard, escapes the tool, and reaches the
     * client as a JSON-RPC 500 reading "Something went wrong while processing
     * the request". Measured on the live instance: two digests lost this way
     * to `cURL error 28` against api.openai.com.
     *
     * An upstream timeout is not a server fault and must degrade the same way
     * an error status does -- no proposals, a log line, and a digest the next
     * Stop can retry.
     */
    public function test_an_upstream_timeout_yields_no_proposals_rather_than_throwing(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out after 20002 milliseconds');
        });

        // The driver reads services.openai.api_key and short-circuits without
        // one, so this must be set or the test passes without ever making a call.
        config(['services.openai.api_key' => 'sk-test-key-not-real-000000000000']);

        $driver = new OpenAiDigestDriver;

        $this->assertSame([], $driver->digest('a transcript', []));
    }
}
