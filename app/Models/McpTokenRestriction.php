<?php

namespace App\Models;

use App\Models\Concerns\MatchesWingPatterns;
use Illuminate\Database\Eloquent\Model;

class McpTokenRestriction extends Model
{
    use MatchesWingPatterns;

    protected $table = 'mcp_token_restrictions';

    protected $primaryKey = 'access_token_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['access_token_id', 'wing_patterns', 'created_at'];

    protected $casts = [
        'wing_patterns' => 'array',
        'created_at' => 'datetime',
    ];
}
