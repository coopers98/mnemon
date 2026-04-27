<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class McpTokenRestriction extends Model
{
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

    public function matches(string $wingSlug): bool
    {
        $patterns = $this->wing_patterns;

        if ($patterns === null) {
            return true;
        }

        foreach ($patterns as $pattern) {
            if ($pattern === $wingSlug) {
                return true;
            }
            if (str_contains($pattern, '*')) {
                $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/';
                if (preg_match($regex, $wingSlug)) {
                    return true;
                }
            }
        }

        return false;
    }
}
