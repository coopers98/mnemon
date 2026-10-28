<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A chat model Mnemon can run on, with what it costs. Prices are per million
 * tokens and null until someone enters them: Mnemon does not guess prices.
 *
 * @property string $provider
 * @property string $model
 * @property string|null $price_in_per_mtok
 * @property string|null $price_cached_in_per_mtok
 * @property string|null $price_out_per_mtok
 * @property bool $is_digest_model
 */
class LlmModel extends Model
{
    /**
     * Providers with a digest driver. A row for any other provider is kept
     * for pricing comparisons but cannot be the digest model.
     */
    public const DIGEST_PROVIDERS = ['openai'];

    private ?object $usageTotals = null;

    protected $fillable = [
        'provider',
        'model',
        'price_in_per_mtok',
        'price_cached_in_per_mtok',
        'price_out_per_mtok',
        'is_digest_model',
        'notes',
    ];

    protected function casts(): array
    {
        return ['is_digest_model' => 'boolean'];
    }

    protected static function booted(): void
    {
        // At most one digest model: marking a row unmarks the others.
        static::saved(function (LlmModel $row) {
            if ($row->is_digest_model) {
                static::query()->whereKeyNot($row->getKey())->where('is_digest_model', true)
                    ->update(['is_digest_model' => false]);
            }
        });
    }

    /**
     * The model marked for digests, if any.
     */
    public static function digestModel(): ?self
    {
        return static::query()->where('is_digest_model', true)
            ->whereIn('provider', self::DIGEST_PROVIDERS)->first();
    }

    public static function for(string $provider, string $model): ?self
    {
        return static::query()->where('provider', $provider)->where('model', $model)->first();
    }

    /**
     * What a call with this usage costs at this row's prices, or null when
     * a price it needs is missing. Cached input falls back to the input price.
     */
    public function costOf(int $promptTokens, int $cachedPromptTokens, int $completionTokens): ?float
    {
        if ($this->price_in_per_mtok === null || $this->price_out_per_mtok === null) {
            return null;
        }

        $in = (float) $this->price_in_per_mtok;
        $cachedIn = $this->price_cached_in_per_mtok === null ? $in : (float) $this->price_cached_in_per_mtok;
        $cached = min($cachedPromptTokens, $promptTokens);

        return (($promptTokens - $cached) * $in + $cached * $cachedIn + $completionTokens * (float) $this->price_out_per_mtok) / 1_000_000;
    }

    /**
     * Calls and tokens recorded against this model, as one row.
     *
     * @return object{calls: int, prompt_tokens: int, cached_prompt_tokens: int, completion_tokens: int}
     */
    public function usageTotals(): object
    {
        return $this->usageTotals ??= $this->queryUsageTotals();
    }

    private function queryUsageTotals(): object
    {
        $row = DB::table('digest_usages')
            ->where('provider', $this->provider)->where('model', $this->model)
            ->selectRaw('COUNT(*) as calls, COALESCE(SUM(prompt_tokens),0) as prompt_tokens, COALESCE(SUM(cached_prompt_tokens),0) as cached_prompt_tokens, COALESCE(SUM(completion_tokens),0) as completion_tokens')
            ->first();

        return (object) [
            'calls' => (int) $row->calls,
            'prompt_tokens' => (int) $row->prompt_tokens,
            'cached_prompt_tokens' => (int) $row->cached_prompt_tokens,
            'completion_tokens' => (int) $row->completion_tokens,
        ];
    }
}
