<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrainSession extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tool_name',
        'source',
        'input',
        'result_count',
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
