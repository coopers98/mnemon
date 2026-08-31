<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D12 — move full-text tokenisation from query time to write time.
 *
 * The expression indexes this replaces were being used, and were fast: on a
 * 23,855-drawer corpus the two bitmap index scans cost about 4ms and produced
 * 1,405 candidates in 102ms. The cost was in the ORDER BY, which recomputed
 * `to_tsvector('english', content)` once per query term for every candidate —
 * on documents up to 30,000 characters. That query took 31,541ms; the
 * identical WHERE ordered by id instead took 2,936ms.
 *
 * PHP gives up at 30 seconds, so every search on a corpus that size failed.
 * The defect was recorded as a sequential scan, which sent the reader to the
 * index; the index was never the problem.
 *
 * A stored generated column tokenises once on write. The GIN index moves onto
 * it, so both the match and the score read a value that already exists.
 *
 * Note for operators: adding a STORED generated column rewrites the table.
 * On a large existing corpus this migration will take a while and needs room
 * for a second copy of the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite has no tsvector; its search path uses LIKE and is unaffected.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['drawers', 'wiki_pages'] as $table) {
            DB::statement("
                ALTER TABLE {$table}
                ADD COLUMN IF NOT EXISTS content_tsv tsvector
                GENERATED ALWAYS AS (to_tsvector('english', coalesce(content, ''))) STORED
            ");

            DB::statement("CREATE INDEX IF NOT EXISTS {$table}_content_tsv_index ON {$table} USING gin (content_tsv)");

            // The expression index is now redundant: every query that used it
            // reads the stored column instead.
            DB::statement("DROP INDEX IF EXISTS {$table}_content_fulltext_index");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['drawers', 'wiki_pages'] as $table) {
            DB::statement("CREATE INDEX IF NOT EXISTS {$table}_content_fulltext_index ON {$table} USING gin (to_tsvector('english', content))");
            DB::statement("DROP INDEX IF EXISTS {$table}_content_tsv_index");
            DB::statement("ALTER TABLE {$table} DROP COLUMN IF EXISTS content_tsv");
        }
    }
};
