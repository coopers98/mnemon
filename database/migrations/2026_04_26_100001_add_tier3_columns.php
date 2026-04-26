<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Item 12: Quality Scoring
        Schema::table('wiki_pages', function (Blueprint $table) {
            $table->float('quality_score')->nullable()->after('confidence_score');
        });

        // Item 14: Retention / Forgetting Curves
        Schema::table('drawers', function (Blueprint $table) {
            $table->unsignedInteger('access_count')->default(0)->after('tier');
            $table->float('retention_score')->default(1.0)->after('access_count');
            $table->timestamp('last_accessed_at')->nullable()->after('retention_score');
        });
    }

    public function down(): void
    {
        Schema::table('wiki_pages', function (Blueprint $table) {
            $table->dropColumn('quality_score');
        });

        Schema::table('drawers', function (Blueprint $table) {
            $table->dropColumn(['access_count', 'retention_score', 'last_accessed_at']);
        });
    }
};
