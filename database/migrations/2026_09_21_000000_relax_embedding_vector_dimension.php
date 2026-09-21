<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D10: `embedding` was `vector(1536)`, sized for `text-embedding-3-small`. Any
 * driver producing a different width could not store a single row — writes
 * failed with "expected 1536 dimensions, not N". That made the documented
 * `ollama` option (`nomic-embed-text`, 768) inert: it was configurable,
 * selectable, and could never work.
 *
 * pgvector only requires a declared width for HNSW/IVFFlat indexes, and there
 * is no index on `embedding` — the only indexes on these tables are GIN
 * full-text. So the width can simply be dropped, and an unconstrained `vector`
 * stores whatever the active driver produces.
 *
 * The cost is that one table can then hold rows of differing widths, and `<=>`
 * raises "different vector dimensions" when the operands disagree. Queries
 * therefore filter on `vector_dims(embedding)`; rows written by another driver
 * are invisible to semantic search rather than fatal to it, and
 * `php artisan mnemon:reembed` migrates them.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite skips the vector column entirely, so there is nothing to alter.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['drawers', 'wiki_pages'] as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN embedding TYPE vector");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Narrowing back is only possible if nothing sits at another width.
        // A row at 768 would abort the ALTER, which is the honest outcome:
        // reverting the schema means re-embedding at 1536 first.
        foreach (['drawers', 'wiki_pages'] as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN embedding TYPE vector(1536)");
        }
    }
};
