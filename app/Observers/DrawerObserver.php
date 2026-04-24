<?php

namespace App\Observers;

use App\Models\Drawer;
use App\Services\EmbeddingManager;

class DrawerObserver
{
    public function __construct(
        private EmbeddingManager $embeddingManager
    ) {}

    public function creating(Drawer $drawer): void
    {
        $this->embedContent($drawer);
    }

    public function updating(Drawer $drawer): void
    {
        if ($drawer->isDirty('content')) {
            $this->embedContent($drawer);
        }
    }

    protected function embedContent(Drawer $drawer): void
    {
        // Skip if not using PostgreSQL (embedding column only exists in pgsql)
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $driver = config('mnemon.embedding.driver');

        if ($driver === 'none') {
            $drawer->embedding = null;

            return;
        }

        $embedding = $this->embeddingManager
            ->driver()
            ->embed($drawer->content ?? '');

        $drawer->embedding = $embedding;
    }
}
