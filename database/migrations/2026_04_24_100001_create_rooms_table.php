<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wing_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();

            $table->unique(['wing_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
