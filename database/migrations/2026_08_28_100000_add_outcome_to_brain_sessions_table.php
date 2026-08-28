<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brain_sessions', function (Blueprint $table) {
            // Existing rows are all successful invocations — the logger had no
            // other outcome to record — so the default backfills them correctly.
            $table->string('outcome')->default('success')->index();
            $table->text('error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('brain_sessions', function (Blueprint $table) {
            $table->dropIndex(['outcome']);
            $table->dropColumn(['outcome', 'error']);
        });
    }
};
