<?php

namespace App\Services\Embeddings;

use App\Contracts\EmbeddingDriverInterface;

class NullDriver implements EmbeddingDriverInterface
{
    public function embed(string $text): ?array
    {
        return null;
    }

    public function dimensions(): int
    {
        return 0;
    }
}
