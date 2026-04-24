<?php

namespace App\Observers;

use App\Models\WikiPage;
use App\Services\EmbeddingManager;

class WikiPageObserver
{
    public function __construct(
        private EmbeddingManager $embeddingManager
    ) {}

    public function creating(WikiPage $wikiPage): void
    {
        $this->embedContent($wikiPage);
    }

    public function updating(WikiPage $wikiPage): void
    {
        if ($wikiPage->isDirty('content')) {
            $this->embedContent($wikiPage);
        }
    }

    protected function embedContent(WikiPage $wikiPage): void
    {
        // Skip if not using PostgreSQL (embedding column only exists in pgsql)
        if (config('database.default') !== 'pgsql') {
            return;
        }

        $driver = config('mnemon.embedding.driver');

        if ($driver === 'none') {
            $wikiPage->embedding = null;

            return;
        }

        $embedding = $this->embeddingManager
            ->driver()
            ->embed($wikiPage->content ?? '');

        $wikiPage->embedding = $embedding;
    }
}
