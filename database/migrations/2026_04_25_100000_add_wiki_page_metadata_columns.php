<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wiki_pages', function (Blueprint $table) {
            $table->string('confidence')->nullable()->after('description');
            $table->json('sources')->nullable()->after('confidence');
            $table->json('related')->nullable()->after('sources');
            $table->unsignedInteger('pending_drawers_since_compile')->default(0)->after('related');
        });
    }

    public function down(): void
    {
        Schema::table('wiki_pages', function (Blueprint $table) {
            $table->dropColumn(['confidence', 'sources', 'related', 'pending_drawers_since_compile']);
        });
    }
};
