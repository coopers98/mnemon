<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wing restrictions keyed on the OAuth client.
     *
     * A separate table rather than a column on `mcp_token_restrictions`, whose
     * primary key *is* `access_token_id`: widening that key means recreating the
     * table on SQLite, and engine-conditional schema work has already produced
     * two defects in this feature.
     *
     * Keying on the client also means a refreshed token inherits its
     * restrictions by construction, and the row dies with the client.
     */
    public function up(): void
    {
        Schema::create('mcp_client_restrictions', function (Blueprint $table) {
            $table->string('client_id')->primary();
            $table->json('wing_patterns')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_client_restrictions');
    }
};
