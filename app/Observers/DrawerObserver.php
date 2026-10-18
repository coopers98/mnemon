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

            // The drawer is committed by now: a failure here must not reach
            // the caller as "Nothing was stored".
            try {
                $this->countForWikiPages($drawer);
            } catch (Throwable $e) {
                Log::error('mnemon: counting a stored drawer for its wiki pages failed', [
                    'drawer_id' => $drawer->id,
                    'message' => $e->getMessage(),
                ]);
            }
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

    protected function countForWikiPages(Drawer $drawer): void
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
        if (! $drawer->isDirty('content') || config('database.default') !== 'pgsql') {
            return;
        }

        if ($this->shouldEmbed()) {
            $this->embedContent($drawer);
        } else {
            // The 'none' driver: an embedding of the old content is wrong.
            $drawer->embedding = null;
        }
    }

    /**
     * Callers decide whether to embed at all (shouldEmbed).
     */
    protected function embedContent(Drawer $drawer): void
    {
        $embedding = $this->embeddingManager
            ->driver()
            ->embed($drawer->content ?? '');

        $drawer->embedding = $embedding;
    }
}
