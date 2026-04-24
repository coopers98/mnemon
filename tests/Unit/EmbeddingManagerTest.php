<?php

namespace Tests\Unit;

use App\Services\EmbeddingManager;
use App\Services\Embeddings\NullDriver;
use App\Services\Embeddings\OllamaDriver;
use App\Services\Embeddings\OpenAiDriver;
use InvalidArgumentException;
use Tests\TestCase;

class EmbeddingManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mnemon.embedding.driver' => 'openai',
            'mnemon.embedding.drivers.openai' => [
                'model' => 'text-embedding-3-small',
                'dimensions' => 1536,
            ],
            'mnemon.embedding.drivers.ollama' => [
                'model' => 'nomic-embed-text',
                'host' => 'http://localhost:11434',
                'dimensions' => 768,
            ],
        ]);
    }

    public function test_resolves_openai_driver_when_configured(): void
    {
        config(['mnemon.embedding.driver' => 'openai']);

        $manager = new EmbeddingManager;
        $driver = $manager->driver();

        $this->assertInstanceOf(OpenAiDriver::class, $driver);
        $this->assertEquals(1536, $driver->dimensions());
    }

    public function test_resolves_ollama_driver_when_configured(): void
    {
        config(['mnemon.embedding.driver' => 'ollama']);

        $manager = new EmbeddingManager;
        $driver = $manager->driver();

        $this->assertInstanceOf(OllamaDriver::class, $driver);
        $this->assertEquals(768, $driver->dimensions());
    }

    public function test_resolves_null_driver_when_configured(): void
    {
        config(['mnemon.embedding.driver' => 'none']);

        $manager = new EmbeddingManager;
        $driver = $manager->driver();

        $this->assertInstanceOf(NullDriver::class, $driver);
    }

    public function test_throws_on_invalid_driver_name(): void
    {
        config(['mnemon.embedding.driver' => 'invalid']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported embedding driver: invalid');

        $manager = new EmbeddingManager;
        $manager->driver();
    }
}
