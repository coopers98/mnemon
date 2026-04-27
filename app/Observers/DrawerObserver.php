<?php

namespace App\Observers;

use App\Models\Drawer;
use App\Models\WikiPage;
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

    public function created(Drawer $drawer): void
    {
        $drawer->load('room.wing');
        $wing = $drawer->room?->wing;

        if (! $wing) {
            return;
        }

        $candidates = collect([$wing->name, $wing->slug, str_replace('-', ':', $wing->slug)])->unique();

        WikiPage::whereIn('name', $candidates)->each(
            fn (WikiPage $page) => $page->increment('pending_drawers_since_compile')
        );
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
