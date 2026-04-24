<?php

namespace App\Contracts;

interface EmbeddingDriverInterface
{
    public function embed(string $text): ?array;

    public function dimensions(): int;
}
