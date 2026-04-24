<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WikiPage extends Model
{
    protected $fillable = [
        'name',
        'type',
        'title',
        'content',
        'description',
        'embedding',
        'last_compiled_at',
    ];

    protected $casts = [
        'type' => 'string',
        'last_compiled_at' => 'datetime',
    ];
}
