<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Functional GIN indexes for the full-text search path (D11).
     *
     * `PalaceSearchService` and `WikiSearchService` match with
     * `to_tsvector('english', content) @@ plainto_tsquery('english', ?)` once per
     * query term. Without an index that is a sequential scan that evaluates
     * `to_tsvector` on every row for every term, and `recall` runs on every user
     * prompt. Measured on 50,000 rows with a six-word prompt: 5,323 ms without
     * the index, 843 ms with it (PostgreSQL 17, `EXPLAIN ANALYZE`).
     *
     * The index expression must match the query expression exactly — same
     * function, same explicit `'english'` regconfig — or the planner will not
     * use it. `to_tsvector(regconfig, text)` is immutable, which is what makes
     * it indexable; the single-argument form is not.
     *
     * PostgreSQL only: SQLite has no tsvector and takes the LIKE path.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("CREATE INDEX IF NOT EXISTS drawers_content_fulltext_index ON drawers USING gin (to_tsvector('english', content))");
        DB::statement("CREATE INDEX IF NOT EXISTS wiki_pages_content_fulltext_index ON wiki_pages USING gin (to_tsvector('english', content))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS drawers_content_fulltext_index');
        DB::statement('DROP INDEX IF EXISTS wiki_pages_content_fulltext_index');
    }
};
