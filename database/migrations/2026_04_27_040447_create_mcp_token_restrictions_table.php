<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mcp_token_restrictions', function (Blueprint $table) {
            $table->string('access_token_id', 100)->primary();
            $table->json('wing_patterns')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('access_token_id')
                ->references('id')->on('oauth_access_tokens')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_token_restrictions');
    }
};
