<?php

namespace App\Services\Embeddings;

use App\Contracts\EmbeddingDriverInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiDriver implements EmbeddingDriverInterface
{
    public function __construct(
        private string $model,
        private int $dimensions
    ) {}

    public function embed(string $text): ?array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.config('services.openai.api_key'),
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/embeddings', [
                'model' => $this->model,
                'input' => $text,
            ]);

            if ($response->failed()) {
                Log::error('OpenAI embedding failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            return $response->json('data.0.embedding');
        } catch (\Exception $e) {
            Log::error('OpenAI embedding exception', [
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
