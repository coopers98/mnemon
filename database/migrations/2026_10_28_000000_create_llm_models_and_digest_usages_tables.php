<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prices live with the model they belong to, edited in the admin panel rather
 * than in .env: swapping the model can no longer leave a stale price behind,
 * and a price change needs no deploy. One row may be marked as the digest
 * model; without one, digests use the configured model.
 *
 * digest_usages keeps the tokens of every model call, never dollars, so a cost
 * is worked out against today's price and a price edit re-prices history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_models', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('openai');
            $table->string('model');
            $table->decimal('price_in_per_mtok', 12, 6)->nullable();
            $table->decimal('price_cached_in_per_mtok', 12, 6)->nullable();
            $table->decimal('price_out_per_mtok', 12, 6)->nullable();
            $table->boolean('is_digest_model')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'model']);
        });

        Schema::create('digest_usages', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('model');
            $table->string('session_id')->nullable();
            $table->unsignedInteger('turn_start')->nullable();
            $table->unsignedInteger('turn_end')->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('cached_prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('reasoning_tokens')->default(0);
            // False when the model answered but its answer could not be used:
            // the call was still paid for.
            $table->boolean('succeeded')->default(true);
            $table->timestamp('created_at')->nullable();

            $table->index(['provider', 'model']);
            $table->index('created_at');
        });

        // The model digests already run on gets a row, so pricing it is an
        // edit rather than a retype. Unmarked: the configuration stays in
        // charge until someone picks a model here.
        $model = config('mnemon.digest.openai_model') ?: config('mnemon.llm.model');
        if (is_string($model) && $model !== '') {
            DB::table('llm_models')->insert([
                'provider' => 'openai',
                'model' => $model,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('digest_usages');
        Schema::dropIfExists('llm_models');
    }
};
