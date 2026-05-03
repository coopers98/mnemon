<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wiki_pending_wings', function (Blueprint $t) {
            $t->id();
            $t->string('wing_slug');
            $t->string('wing_name');
            $t->text('rationale')->nullable();
            $t->json('drawer_payload');
            $t->string('status', 20)->default('pending')->index();
            $t->string('proposed_by_session_id', 100)->nullable();
            $t->string('proposed_by_token_id', 100)->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_pending_wings');
    }
};
