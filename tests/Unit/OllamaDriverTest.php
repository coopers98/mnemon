<?php

namespace Tests\Unit;

use App\Services\Embeddings\OllamaDriver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OllamaDriverTest extends TestCase
{
    public function test_successful_embedding_returns_array(): void
    {
        Http::fake([
            'localhost:11434/api/embed' => Http::response([
                'embeddings' => [
                    array_fill(0, 768, 0.2),
                ],
            ], 200),
        ]);

        $driver = new OllamaDriver('nomic-embed-text', 'http://localhost:11434', 768);
        $result = $driver->embed('test content');

        $this->assertIsArray($result);
        $this->assertCount(768, $result);
    }

    public function test_connection_failure_returns_null(): void
    {
        Http::fake([
            'localhost:11434/api/embed' => Http::response([], 500),
        ]);

        $driver = new OllamaDriver('nomic-embed-text', 'http://localhost:11434', 768);
        $result = $driver->embed('test content');

        $this->assertNull($result);
    }

    public function test_dimensions_returns_configured_value(): void
    {
        $driver = new OllamaDriver('nomic-embed-text', 'http://localhost:11434', 768);

        $this->assertEquals(768, $driver->dimensions());
    }
}
