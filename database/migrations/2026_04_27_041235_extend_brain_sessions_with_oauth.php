<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brain_sessions', function (Blueprint $t) {
            $t->uuid('oauth_client_id')->nullable()->after('source')->index();
            $t->foreignId('user_id')->nullable()->after('oauth_client_id')->constrained()->nullOnDelete();
            $t->string('access_token_id', 100)->nullable()->after('user_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('brain_sessions', function (Blueprint $t) {
            $t->dropForeign(['user_id']);
            $t->dropColumn(['oauth_client_id', 'user_id', 'access_token_id']);
        });
    }
};
