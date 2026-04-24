<?php

namespace Tests\Unit;

use App\Services\Embeddings\OpenAiDriver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiDriverTest extends TestCase
{
    public function test_successful_embedding_returns_array_of_correct_dimensions(): void
    {
        config(['services.openai.api_key' => 'test-key']);

        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [
                    [
                        'embedding' => array_fill(0, 1536, 0.1),
                    ],
                ],
            ], 200),
        ]);

        $driver = new OpenAiDriver('text-embedding-3-small', 1536);
        $result = $driver->embed('test content');

        $this->assertIsArray($result);
        $this->assertCount(1536, $result);
    }

    public function test_api_error_returns_null(): void
    {
        config(['services.openai.api_key' => 'test-key']);

        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'error' => ['message' => 'Invalid API key'],
            ], 401),
        ]);

        $driver = new OpenAiDriver('text-embedding-3-small', 1536);
        $result = $driver->embed('test content');

        $this->assertNull($result);
    }

    public function test_uses_correct_model_from_config(): void
    {
        config(['services.openai.api_key' => 'test-key']);

        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [
                    ['embedding' => array_fill(0, 1536, 0.1)],
                ],
            ], 200),
        ]);

        $driver = new OpenAiDriver('text-embedding-3-small', 1536);
        $driver->embed('test content');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.openai.com/v1/embeddings' &&
                   $request['model'] === 'text-embedding-3-small' &&
                   $request['input'] === 'test content';
        });
    }

    public function test_dimensions_returns_configured_value(): void
    {
        $driver = new OpenAiDriver('text-embedding-3-small', 1536);

        $this->assertEquals(1536, $driver->dimensions());
    }
}
