<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * created_at says when a drawer was stored; occurred_at says when the
 * conversation it came from happened. They differ for imported history. Search
 * recency and wiki compile order by occurred_at; retention still decays from
 * created_at. Null means "no better answer than created_at".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drawers', function (Blueprint $table) {
            $table->timestamp('occurred_at')->nullable()->after('last_accessed_at');
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::table('drawers', function (Blueprint $table) {
            $table->dropIndex(['occurred_at']);
            $table->dropColumn('occurred_at');
        });
    }
};
