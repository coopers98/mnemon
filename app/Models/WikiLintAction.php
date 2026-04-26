<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WikiLintAction extends Model
{
    protected $fillable = [
        'page_id',
        'action',
        'before',
        'after',
        'performed_at',
    ];

    protected $casts = [
        'performed_at' => 'datetime',
    ];
}
