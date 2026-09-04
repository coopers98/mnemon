<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A content fingerprint, so the same drawer is not stored twice.
 *
 * Cron-launched agent sessions produce byte-identical transcripts every day.
 * Measured on the live palace before this landed: 31 duplicate groups and 56
 * redundant copies, 17% of everything stored. Each one cost an embedding call
 * and made its wiki page look staler than it was.
 *
 * Indexed with room_id because that is the scope duplicates are judged in — the
 * same content in two rooms is two different observations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drawers', function (Blueprint $table) {
            $table->string('content_hash', 64)->nullable()->after('content');
            $table->index(['room_id', 'content_hash']);
        });

        // Backfill, or the first repeat of anything already stored would slip
        // through the check on the day this deploys.
        DB::table('drawers')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('drawers')
                    ->where('id', $row->id)
                    ->update(['content_hash' => hash('sha256', (string) $row->content)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('drawers', function (Blueprint $table) {
            $table->dropIndex(['room_id', 'content_hash']);
            $table->dropColumn('content_hash');
        });
    }
};
