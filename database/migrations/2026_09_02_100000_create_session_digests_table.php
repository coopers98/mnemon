<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per transcript slice that has been digested.
     *
     * The capture hook can dispatch the same slice more than once — two workers
     * racing, or a session resuming and replaying a range it already sent — and
     * each dispatch costs a reader call and creates drawers. The unique index
     * makes "this slice is already done" a database fact rather than a
     * check-then-act race in the client.
     */
    public function up(): void
    {
        Schema::create('session_digests', function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 100);
            $table->unsignedInteger('turn_start');
            $table->unsignedInteger('turn_end');
            $table->json('drawer_ids');
            $table->timestamps();

            $table->unique(['session_id', 'turn_start', 'turn_end'], 'session_digests_slice_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_digests');
    }
};
