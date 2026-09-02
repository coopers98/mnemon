<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A transcript slice that has already been digested.
 *
 * @property string $session_id
 * @property int $turn_start
 * @property int $turn_end
 * @property array<int, int> $drawer_ids
 */
class SessionDigest extends Model
{
    protected $fillable = [
        'session_id',
        'turn_start',
        'turn_end',
        'drawer_ids',
    ];

    protected function casts(): array
    {
        return ['drawer_ids' => 'array'];
    }
}
