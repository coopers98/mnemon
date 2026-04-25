<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    protected $fillable = [
        'name',
        'key_hash',
        'scopes',
        'wing_restrictions',
        'last_used_at',
        'revoked_at',
    ];

    protected $casts = [
        'scopes' => 'array',
        'wing_restrictions' => 'array',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function hasScope(string $scope): bool
    {
        $scopes = $this->scopes ?? [];

        return in_array('*', $scopes) || in_array($scope, $scopes);
    }

    public function canAccessWing(string $wingSlug): bool
    {
        $restrictions = $this->wing_restrictions;

        if (empty($restrictions)) {
            return true;
        }

        foreach ($restrictions as $pattern) {
            if ($pattern === $wingSlug) {
                return true;
            }

            // Support wildcard patterns like 'project:*'
            if (str_contains($pattern, '*')) {
                $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/';
                if (preg_match($regex, $wingSlug)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function revoke(): void
    {
        $this->update(['revoked_at' => now()]);
    }

    public static function generate(string $name, array $scopes, ?array $wingRestrictions = null): array
    {
        $plaintext = Str::random(64);
        $keyHash = hash('sha256', $plaintext);

        $apiKey = static::create([
            'name' => $name,
            'key_hash' => $keyHash,
            'scopes' => $scopes,
            'wing_restrictions' => $wingRestrictions,
        ]);

        return [
            'key' => $plaintext,
            'model' => $apiKey,
        ];
    }
}
