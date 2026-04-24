<?php

namespace App\Services\Embeddings;

use App\Contracts\EmbeddingDriverInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OllamaDriver implements EmbeddingDriverInterface
{
    public function __construct(
        private string $model,
        private string $host,
        private int $dimensions
    ) {}

    public function embed(string $text): ?array
    {
        try {
            $response = Http::post("{$this->host}/api/embed", [
                'model' => $this->model,
                'input' => $text,
            ]);

            if ($response->failed()) {
                Log::error('Ollama embedding failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            return $response->json('embeddings.0');
        } catch (\Exception $e) {
            Log::error('Ollama embedding exception', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }
}
