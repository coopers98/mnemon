<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WikiPendingWing extends Model
{
    use HasFactory;

    protected $fillable = [
        'wing_slug',
        'wing_name',
        'rationale',
        'drawer_payload',
        'status',
        'proposed_by_session_id',
        'proposed_by_token_id',
        'decided_at',
    ];

    protected $casts = [
        'drawer_payload' => 'array',
        'decided_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';
}
