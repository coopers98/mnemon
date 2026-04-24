<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drawers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->string('source')->nullable();
            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        // Add vector column for Postgres only
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE drawers ADD COLUMN embedding vector(1536)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('drawers');
    }
};
