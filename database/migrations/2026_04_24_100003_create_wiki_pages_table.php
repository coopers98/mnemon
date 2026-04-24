<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_pages', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('type'); // person/project/concept/decision/synthesis
            $table->string('title');
            $table->text('content');
            $table->string('description')->nullable();
            $table->timestamp('last_compiled_at')->nullable();
            $table->timestamps();
        });

        // Add vector column for Postgres only
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE wiki_pages ADD COLUMN embedding vector(1536)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_pages');
    }
};
