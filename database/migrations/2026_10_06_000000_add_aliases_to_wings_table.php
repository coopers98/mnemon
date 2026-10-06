<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A capture names its project by repository ("recital-lineup"), but a wing is
 * named for what the project is ("project:cora"). Aliases are the other names a
 * project wing answers to, so session_digest can file a session by the project
 * it ran in rather than by what the digest model guesses from the transcript.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wings', function (Blueprint $table) {
            $table->json('aliases')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('wings', function (Blueprint $table) {
            $table->dropColumn('aliases');
        });
    }
};
