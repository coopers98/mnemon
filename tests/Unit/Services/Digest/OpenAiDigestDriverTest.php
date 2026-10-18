<?php

namespace Tests\Unit\Services\Digest;

use App\Services\Digest\DigestUnavailable;
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

    public function test_uses_the_shared_llm_model_by_default(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{"proposals":[]}']]]])]);
        config(['services.openai.api_key' => 'sk-test']);
        config(['mnemon.llm.model' => 'gpt-shared', 'mnemon.digest.openai_model' => null]);

        (new OpenAiDigestDriver)->digest('t', []);

        Http::assertSent(fn ($request) => $request['model'] === 'gpt-shared');
    }

    public function test_a_digest_specific_model_overrides_the_shared_one(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{"proposals":[]}']]]])]);
        config(['services.openai.api_key' => 'sk-test']);
        config(['mnemon.llm.model' => 'gpt-shared', 'mnemon.digest.openai_model' => 'gpt-digest']);

        (new OpenAiDigestDriver)->digest('t', []);

        Http::assertSent(fn ($request) => $request['model'] === 'gpt-digest');
    }

    public function test_tells_the_model_which_wing_the_session_belongs_to(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{"proposals":[]}']]]])]);
        config(['services.openai.api_key' => 'sk-test']);

        (new OpenAiDigestDriver)->digest('t', ['session_wing' => 'project-mnemon']);

        Http::assertSent(fn ($request) => str_contains($request['messages'][0]['content'], 'project-mnemon'));
    }

    public function test_a_valid_empty_proposal_list_is_an_empty_result_not_a_failure(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{"proposals":[]}']]]])]);
        config(['services.openai.api_key' => 'sk-test']);

        $this->assertSame([], (new OpenAiDigestDriver)->digest('t', []));
    }

    /**
     * Unparseable model output used to come back as [] -- indistinguishable
     * from "nothing worth keeping", so the slice was recorded as digested and
     * its turns were lost. The model may well answer properly next time.
     */
    public function test_unparseable_model_output_is_a_retryable_failure(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'not json']]]])]);
        config(['services.openai.api_key' => 'sk-test']);

        $e = $this->failureOf(fn () => (new OpenAiDigestDriver)->digest('t', []));

        $this->assertTrue($e->retryable);
    }

    /**
     * A timeout throws ConnectionException rather than returning a response.
     * It used to be swallowed into [] -- a "successful" empty digest, recorded
     * as such, so the next Stop moved on and the turns were never digested.
     * Measured on the live instance: digests lost to `cURL error 28`.
     */
    public function test_an_upstream_timeout_is_a_retryable_failure(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out after 20002 milliseconds');
        });

        // The driver reads services.openai.api_key and short-circuits without
        // one, so this must be set or the test passes without ever making a call.
        config(['services.openai.api_key' => 'sk-test-key-not-real-000000000000']);

        $e = $this->failureOf(fn () => (new OpenAiDigestDriver)->digest('a transcript', []));

        $this->assertTrue($e->retryable);
        $this->assertStringContainsString('timed out', $e->getMessage());
    }

    public function test_rate_limiting_is_a_retryable_failure(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'slow down']], 429)]);
        config(['services.openai.api_key' => 'sk-test']);

        $e = $this->failureOf(fn () => (new OpenAiDigestDriver)->digest('t', []));

        $this->assertTrue($e->retryable);
        $this->assertStringContainsString('429', $e->getMessage());
    }

    public function test_an_upstream_server_error_is_a_retryable_failure(): void
    {
        Http::fake(['api.openai.com/*' => Http::response('<html>bad gateway</html>', 502)]);
        config(['services.openai.api_key' => 'sk-test']);

        $e = $this->failureOf(fn () => (new OpenAiDigestDriver)->digest('t', []));

        $this->assertTrue($e->retryable);
        $this->assertStringContainsString('502', $e->getMessage());
    }

    public function test_a_client_error_other_than_429_is_not_retryable(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'bad key']], 401)]);
        config(['services.openai.api_key' => 'sk-test']);

        $e = $this->failureOf(fn () => (new OpenAiDigestDriver)->digest('t', []));

        $this->assertFalse($e->retryable);
        $this->assertStringContainsString('401', $e->getMessage());
    }

    public function test_a_missing_api_key_is_a_failure_that_is_not_retryable(): void
    {
        Http::fake();
        config(['services.openai.api_key' => '']);

        $e = $this->failureOf(fn () => (new OpenAiDigestDriver)->digest('t', []));

        $this->assertFalse($e->retryable);
        Http::assertNothingSent();
    }

    private function failureOf(callable $call): DigestUnavailable
    {
        try {
            $call();
        } catch (DigestUnavailable $e) {
            return $e;
        }

        $this->fail('expected DigestUnavailable, the call returned normally');
    }
}
