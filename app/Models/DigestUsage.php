<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The tokens one digest model call used. Tokens, not dollars: cost is worked
 * out against the model's current price (LlmModel::costOf).
 *
 * @property string $provider
 * @property string $model
 * @property int $prompt_tokens
 * @property int $cached_prompt_tokens
 * @property int $completion_tokens
 * @property int $reasoning_tokens
 * @property bool $succeeded
 */
class DigestUsage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'provider',
        'model',
        'session_id',
        'turn_start',
        'turn_end',
        'prompt_tokens',
        'cached_prompt_tokens',
        'completion_tokens',
        'reasoning_tokens',
        'succeeded',
    ];

    protected function casts(): array
    {
        return ['succeeded' => 'boolean'];
    }
}
