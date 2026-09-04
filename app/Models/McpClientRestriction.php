<?php

namespace App\Models;

use App\Models\Concerns\MatchesWingPatterns;
use Illuminate\Database\Eloquent\Model;

/**
 * The wings an OAuth client may reach.
 *
 * Keyed on the client rather than the access token, because a device's
 * permitted wings are a property of the device, not of whichever hour-long
 * token it currently holds. Keying on the token meant a refreshed token had no
 * row, and a missing row means unrestricted — so a restricted device silently
 * became an all-wings device one hour after consent.
 *
 * @property string $client_id
 * @property array<int, string>|null $wing_patterns
 */
class McpClientRestriction extends Model
{
    use MatchesWingPatterns;

    protected $table = 'mcp_client_restrictions';

    protected $primaryKey = 'client_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['client_id', 'wing_patterns', 'created_at'];

    protected $casts = [
        'wing_patterns' => 'array',
        'created_at' => 'datetime',
    ];
}
