<?php

use App\Services\ConnectedDeviceReport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `source` is a display string: "claude-code@dogfood as me@example.com
 * (token …53b3)". It embeds the token id, and device tokens refresh hourly, so
 * grouping the audit trail by `source` made one machine look like sixteen
 * devices — and answering "what is connected, and when did it last call in"
 * meant parsing a human-readable string.
 *
 * `device` stores the bare name alongside it. `source` is untouched: it is what
 * the audit views already render, and rewriting history to drop the token id
 * would lose the ability to tie a row to the exact credential that made it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brain_sessions', function (Blueprint $table) {
            $table->string('device')->nullable()->after('source');
            $table->index(['device', 'created_at']);
        });

        (new ConnectedDeviceReport)->backfill();
    }

    public function down(): void
    {
        Schema::table('brain_sessions', function (Blueprint $table) {
            $table->dropIndex(['device', 'created_at']);
            $table->dropColumn('device');
        });
    }
};
