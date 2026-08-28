<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrainSession extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tool_name',
        'source',
        'oauth_client_id',
        'user_id',
        'access_token_id',
        'input',
        'result_count',
        'outcome',
        'error',
    ];

    protected $casts = [
        'input' => 'array',
        'created_at' => 'datetime',
    ];

    public static function boot(): void
    {
        parent::boot();

        static::creating(function (BrainSession $session) {
            $session->created_at = now();
        });
    }
}
