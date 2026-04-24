<?php

namespace App\Services;

use App\Contracts\EmbeddingDriverInterface;
use App\Services\Embeddings\NullDriver;
use App\Services\Embeddings\OllamaDriver;
use App\Services\Embeddings\OpenAiDriver;
use InvalidArgumentException;

class EmbeddingManager
{
    public function driver(?string $name = null): EmbeddingDriverInterface
    {
        $name = $name ?? config('mnemon.embedding.driver');

        return match ($name) {
            'openai' => $this->createOpenAiDriver(),
            'ollama' => $this->createOllamaDriver(),
            'none' => new NullDriver,
            default => throw new InvalidArgumentException("Unsupported embedding driver: {$name}"),
        };
    }

    protected function createOpenAiDriver(): OpenAiDriver
    {
        $config = config('mnemon.embedding.drivers.openai');

        return new OpenAiDriver(
            model: $config['model'],
            dimensions: $config['dimensions']
        );
    }

    protected function createOllamaDriver(): OllamaDriver
    {
        $config = config('mnemon.embedding.drivers.ollama');

        return new OllamaDriver(
            model: $config['model'],
            host: $config['host'],
            dimensions: $config['dimensions']
        );
    }
}
