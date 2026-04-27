<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('api_keys');
    }

    public function down(): void
    {
        // Intentionally empty — recreating the table doesn't restore data.
        // Roll back via `git revert` and DB restore from pre-deploy backup.
    }
};
