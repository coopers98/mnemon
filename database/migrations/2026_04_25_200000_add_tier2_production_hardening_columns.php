<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Item 1: Confidence Scoring + Decay
        Schema::table('wiki_pages', function (Blueprint $table) {
            $table->float('confidence_score')->nullable()->after('confidence');
            $table->unsignedInteger('source_count')->default(0)->after('confidence_score');
            $table->timestamp('last_accessed_at')->nullable()->after('last_compiled_at');
        });

        // Item 2: Supersession
        Schema::table('wiki_pages', function (Blueprint $table) {
            $table->unsignedInteger('revision_count')->default(1)->after('last_accessed_at');
            $table->string('previous_content_hash')->nullable()->after('revision_count');
        });

        // Item 3: Consolidation Tiers
        Schema::table('drawers', function (Blueprint $table) {
            $table->string('tier')->default('raw')->after('metadata');
        });
    }

    public function down(): void
    {
        Schema::table('wiki_pages', function (Blueprint $table) {
            $table->dropColumn([
                'confidence_score',
                'source_count',
                'last_accessed_at',
                'revision_count',
                'previous_content_hash',
            ]);
        });

        Schema::table('drawers', function (Blueprint $table) {
            $table->dropColumn('tier');
        });
    }
};
