<?php

namespace App\Observers;

use App\Models\Drawer;
use App\Models\WikiPage;
use App\Services\EmbeddingManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DrawerObserver
{
    public function __construct(
        private EmbeddingManager $embeddingManager
    ) {}

    /**
     * The embedding and the wiki counters are written after the surrounding
     * transaction commits -- immediately when there is none, as for drawer_add
     * and the admin panel. A session digest writes every drawer inside one
     * transaction, and embedding there held its locks, the wiki page counter
     * rows among them, for an HTTP call per drawer. A digest that rolls back
     * now increments nothing and embeds nothing.
     */
    public function created(Drawer $drawer): void
    {
        DB::afterCommit(function () use ($drawer) {
            if ($this->shouldEmbed()) {
                try {
                    $this->embedStored($drawer);
                } catch (Throwable $e) {
                    // The drawer is committed; failing here would fail a
                    // digest that succeeded and have the client repeat it.
                    Log::error('mnemon: embedding a stored drawer failed', [
                        'drawer_id' => $drawer->id,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            $this->countForWikiPages($drawer);
        });
    }

    protected function shouldEmbed(): bool
    {
        // The embedding column only exists in pgsql.
        return config('database.default') === 'pgsql'
            && config('mnemon.embedding.driver') !== 'none';
    }

    protected function embedStored(Drawer $drawer): void
    {
        $this->embedContent($drawer);
        $drawer->saveQuietly();
    }

    private function countForWikiPages(Drawer $drawer): void
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
